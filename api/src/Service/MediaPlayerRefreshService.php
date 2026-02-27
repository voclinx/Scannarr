<?php

namespace App\Service;

use App\Repository\MediaPlayerInstanceRepository;
use Psr\Log\LoggerInterface;
use Throwable;

class MediaPlayerRefreshService
{
    public function __construct(
        private readonly PlexService $plexService,
        private readonly JellyfinService $jellyfinService,
        private readonly MediaPlayerInstanceRepository $mediaPlayerRepository,
        private readonly LoggerInterface $logger,
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
            $type = $instance->getType();

            try {
                if ($type === 'plex') {
                    $refreshed = $this->plexService->refreshAllMovieSections($instance);
                    $result['plex_refreshed'] += $refreshed;
                    $this->logger->info('Plex library refreshed', [
                        'instance' => $instance->getName(),
                        'sections_refreshed' => $refreshed,
                    ]);
                } elseif ($type === 'jellyfin') {
                    $success = $this->jellyfinService->refreshLibrary($instance);
                    if ($success) {
                        ++$result['jellyfin_refreshed'];
                        $this->logger->info('Jellyfin library refreshed', [
                            'instance' => $instance->getName(),
                        ]);
                    } else {
                        $result['errors'][] = sprintf('Jellyfin refresh failed for %s', $instance->getName());
                    }
                }
            } catch (Throwable $e) {
                $errorMessage = sprintf(
                    '%s refresh failed for %s: %s',
                    ucfirst($type),
                    $instance->getName(),
                    $e->getMessage(),
                );
                $result['errors'][] = $errorMessage;
                $this->logger->warning($errorMessage);
            }
        }

        return $result;
    }
}
