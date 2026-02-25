<?php

namespace App\Service;

use App\Entity\MediaPlayerInstance;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

class PlexService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
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
        } catch (\Exception $e) {
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

        return array_map(fn(array $d) => [
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
     * Make a request to the Plex API.
     *
     * @return array<string, mixed>
     * @throws \RuntimeException
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

            throw new \RuntimeException(
                sprintf('Plex API error: %s', $e->getMessage()),
                0,
                $e
            );
        }
    }
}
