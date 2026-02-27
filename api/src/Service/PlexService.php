<?php

namespace App\Service;

use App\Entity\MediaPlayerInstance;
use Exception;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

class PlexService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Test connection to a Plex instance.
     *
     * @return array{success: bool, name?: string, version?: string, error?: string}
     */
    public function testConnection(MediaPlayerInstance $instance): array
    {
        try {
            $data = $this->request($instance, 'GET', '/');

            $container = $data['MediaContainer'] ?? [];

            return [
                'success' => true,
                'name' => $container['friendlyName'] ?? 'Unknown',
                'version' => $container['version'] ?? 'unknown',
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get library sections from Plex.
     *
     * @return array<int, array{key: string, title: string, type: string}>
     */
    public function getLibrarySections(MediaPlayerInstance $instance): array
    {
        $data = $this->request($instance, 'GET', '/library/sections');
        $directories = $data['MediaContainer']['Directory'] ?? [];

        return array_map(fn (array $d): array => [
            'key' => $d['key'] ?? '',
            'title' => $d['title'] ?? '',
            'type' => $d['type'] ?? '',
        ], $directories);
    }

    /**
     * Get all movies from a Plex library section.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getMoviesFromSection(MediaPlayerInstance $instance, string $sectionKey): array
    {
        $data = $this->request($instance, 'GET', "/library/sections/{$sectionKey}/all");

        return $data['MediaContainer']['Metadata'] ?? [];
    }

    /**
     * Refresh a specific Plex library section.
     */
    public function refreshLibrary(MediaPlayerInstance $instance, string $sectionKey): bool
    {
        $url = rtrim($instance->getUrl() ?? '', '/') . "/library/sections/{$sectionKey}/refresh";

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'X-Plex-Token' => $instance->getToken(),
                ],
                'timeout' => 10,
            ]);

            return $response->getStatusCode() === 200;
        } catch (Throwable $e) {
            $this->logger->warning('Plex refresh failed', [
                'instance' => $instance->getName(),
                'section' => $sectionKey,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Refresh all movie library sections on a Plex instance.
     *
     * @return int Number of sections successfully refreshed
     */
    public function refreshAllMovieSections(MediaPlayerInstance $instance): int
    {
        try {
            $sections = $this->getLibrarySections($instance);
        } catch (Throwable $e) {
            $this->logger->warning('Failed to get Plex library sections for refresh', [
                'instance' => $instance->getName(),
                'error' => $e->getMessage(),
            ]);

            return 0;
        }

        $refreshed = 0;
        foreach ($sections as $section) {
            if (($section['type'] ?? '') !== 'movie') {
                continue;
            }

            if ($this->refreshLibrary($instance, $section['key'])) {
                ++$refreshed;
            }
        }

        return $refreshed;
    }

    /**
     * Make a request to the Plex API.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    private function request(MediaPlayerInstance $instance, string $method, string $endpoint): array
    {
        $url = rtrim($instance->getUrl() ?? '', '/') . $endpoint;

        try {
            $response = $this->httpClient->request($method, $url, [
                'headers' => [
                    'X-Plex-Token' => $instance->getToken(),
                    'Accept' => 'application/json',
                ],
                'timeout' => 15,
            ]);

            return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (ExceptionInterface $e) {
            $this->logger->error('Plex API request failed', [
                'instance' => $instance->getName(),
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException(
                sprintf('Plex API error: %s', $e->getMessage()),
                0,
                $e,
            );
        }
    }
}
