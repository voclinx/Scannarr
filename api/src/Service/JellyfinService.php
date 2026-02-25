<?php

namespace App\Service;

use App\Entity\MediaPlayerInstance;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

class JellyfinService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Test connection to a Jellyfin instance.
     *
     * @return array{success: bool, name?: string, version?: string, error?: string}
     */
    public function testConnection(MediaPlayerInstance $instance): array
    {
        try {
            $data = $this->request($instance, 'GET', '/System/Info');

            return [
                'success' => true,
                'name' => $data['ServerName'] ?? 'Unknown',
                'version' => $data['Version'] ?? 'unknown',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get all movies from Jellyfin.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getMovies(MediaPlayerInstance $instance): array
    {
        $data = $this->request($instance, 'GET', '/Items', [
            'query' => [
                'IncludeItemTypes' => 'Movie',
                'Recursive' => 'true',
                'Fields' => 'Path,Overview,Genres',
            ],
        ]);

        return $data['Items'] ?? [];
    }

    /**
     * Make a request to the Jellyfin API.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     * @throws \RuntimeException
     */
    private function request(MediaPlayerInstance $instance, string $method, string $endpoint, array $options = []): array
    {
        $url = rtrim($instance->getUrl() ?? '', '/') . $endpoint;

        $options['headers'] = array_merge($options['headers'] ?? [], [
            'X-Emby-Token' => $instance->getToken(),
            'Accept' => 'application/json',
        ]);

        $options['timeout'] = $options['timeout'] ?? 15;

        try {
            $response = $this->httpClient->request($method, $url, $options);

            return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (ExceptionInterface $e) {
            $this->logger->error('Jellyfin API request failed', [
                'instance' => $instance->getName(),
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                sprintf('Jellyfin API error: %s', $e->getMessage()),
                0,
                $e
            );
        }
    }
}
