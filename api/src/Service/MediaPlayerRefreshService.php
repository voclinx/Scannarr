<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\MediaPlayerInstance;
use App\ExternalService\MediaPlayer\JellyfinService;
use App\ExternalService\MediaPlayer\PlexService;
use App\Repository\MediaPlayerInstanceRepository;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class MediaPlayerRefreshService
{
    public function __construct(
        private PlexService $plexService,
        private JellyfinService $jellyfinService,
        private MediaPlayerInstanceRepository $mediaPlayerRepository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Refresh all active media player libraries.
     * Best-effort: logs warnings on failure but never throws.
     *
     * @return array{plex_refreshed: int, jellyfin_refreshed: int, errors: list<string>}
     */
    public function refreshAll(): array
    {
        $result = [
            'plex_refreshed' => 0,
            'jellyfin_refreshed' => 0,
            'errors' => [],
        ];

        $instances = $this->mediaPlayerRepository->findBy(['isActive' => true]);

        if (count($instances) === 0) {
            $this->logger->debug('No active media player instances to refresh');

            return $result;
        }

        foreach ($instances as $instance) {
            $this->refreshInstance($instance, $result);
        }

        return $result;
    }

    /**
     * Refresh a single media player instance.
     *
     * @param array{plex_refreshed: int, jellyfin_refreshed: int, errors: list<string>} $result
     */
    private function refreshInstance(
        MediaPlayerInstance $instance,
        array &$result,
    ): void {
        $type = $instance->getType();

        try {
            $this->doRefreshByType($instance, $type, $result);
        } catch (Throwable $e) {
            $errorMessage = sprintf(
                '%s refresh failed for %s: %s',
                ucfirst((string)$type),
                $instance->getName(),
                $e->getMessage(),
            );
            $result['errors'][] = $errorMessage;
            $this->logger->warning($errorMessage);
        }
    }

    /**
     * @param array{plex_refreshed: int, jellyfin_refreshed: int, errors: list<string>} $result
     */
    private function doRefreshByType(
        MediaPlayerInstance $instance,
        string $type,
        array &$result,
    ): void {
        if ($type === 'plex') {
            $this->refreshPlex($instance, $result);

            return;
        }

        if ($type === 'jellyfin') {
            $this->refreshJellyfin($instance, $result);
        }
    }

    /** @param array{plex_refreshed: int, jellyfin_refreshed: int, errors: list<string>} $result */
    private function refreshPlex(MediaPlayerInstance $instance, array &$result): void
    {
        $refreshed = $this->plexService->refreshAllMovieSections($instance);
        $result['plex_refreshed'] += $refreshed;
        $this->logger->info('Plex library refreshed', [
            'instance' => $instance->getName(),
            'sections_refreshed' => $refreshed,
        ]);
    }

    /** @param array{plex_refreshed: int, jellyfin_refreshed: int, errors: list<string>} $result */
    private function refreshJellyfin(MediaPlayerInstance $instance, array &$result): void
    {
        $success = $this->jellyfinService->refreshLibrary($instance);
        if (!$success) {
            $result['errors'][] = sprintf('Jellyfin refresh failed for %s', $instance->getName());

            return;
        }

        ++$result['jellyfin_refreshed'];
        $this->logger->info('Jellyfin library refreshed', [
            'instance' => $instance->getName(),
        ]);
    }
}
