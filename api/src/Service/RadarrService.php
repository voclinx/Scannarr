<?php

namespace App\Service;

use App\Entity\RadarrInstance;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

class RadarrService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Test connection to a Radarr instance.
     *
     * @return array{success: bool, version?: string, movies_count?: int, error?: string}
     */
    public function testConnection(RadarrInstance $instance): array
    {
        try {
            $status = $this->request($instance, 'GET', '/api/v3/system/status');
            $movies = $this->request($instance, 'GET', '/api/v3/movie');

            return [
                'success' => true,
                'version' => $status['version'] ?? 'unknown',
                'movies_count' => is_array($movies) ? count($movies) : 0,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get system status from Radarr.
     *
     * @return array<string, mixed>
     */
    public function getSystemStatus(RadarrInstance $instance): array
    {
        return $this->request($instance, 'GET', '/api/v3/system/status');
    }

    /**
     * Get root folders from Radarr.
     *
     * @return array<int, array{id: int, path: string, freeSpace: int}>
     */
    public function getRootFolders(RadarrInstance $instance): array
    {
        return $this->request($instance, 'GET', '/api/v3/rootfolder');
    }

    /**
     * Get all movies from Radarr.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllMovies(RadarrInstance $instance): array
    {
        return $this->request($instance, 'GET', '/api/v3/movie');
    }

    /**
     * Get a single movie from Radarr by its Radarr ID.
     *
     * @return array<string, mixed>
     */
    public function getMovie(RadarrInstance $instance, int $radarrId): array
    {
        return $this->request($instance, 'GET', "/api/v3/movie/{$radarrId}");
    }

    /**
     * Get movie files for a given movie Radarr ID.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getMovieFiles(RadarrInstance $instance, int $radarrMovieId): array
    {
        return $this->request($instance, 'GET', '/api/v3/moviefile', [
            'query' => ['movieId' => $radarrMovieId],
        ]);
    }

    /**
     * Delete a movie from Radarr (dereference only, no file deletion).
     *
     * @return void
     */
    public function deleteMovie(RadarrInstance $instance, int $radarrId, bool $deleteFiles = false, bool $addExclusion = false): void
    {
        $this->request($instance, 'DELETE', "/api/v3/movie/{$radarrId}", [
            'query' => [
                'deleteFiles' => $deleteFiles ? 'true' : 'false',
                'addImportExclusion' => $addExclusion ? 'true' : 'false',
            ],
        ]);
    }

    /**
     * Delete a movie file reference in Radarr.
     *
     * @return void
     */
    public function deleteMovieFile(RadarrInstance $instance, int $movieFileId): void
    {
        $this->request($instance, 'DELETE', "/api/v3/moviefile/{$movieFileId}");
    }

    /**
     * Update a movie in Radarr (e.g., to disable monitoring/auto-search).
     *
     * @param array<string, mixed> $movieData Full movie object from Radarr with modifications
     * @return array<string, mixed>
     */
    public function updateMovie(RadarrInstance $instance, int $radarrId, array $movieData): array
    {
        return $this->request($instance, 'PUT', "/api/v3/movie/{$radarrId}", [
            'json' => $movieData,
        ]);
    }

    /**
     * Make an API request to a Radarr instance.
     *
     * @param array<string, mixed> $options Additional request options
     * @return array<string, mixed>
     * @throws \RuntimeException If the request fails
     */
    private function request(RadarrInstance $instance, string $method, string $endpoint, array $options = []): array
    {
        $url = rtrim($instance->getUrl() ?? '', '/') . $endpoint;

        $options['headers'] = array_merge($options['headers'] ?? [], [
            'X-Api-Key' => $instance->getApiKey(),
            'Accept' => 'application/json',
        ]);

        $options['timeout'] = $options['timeout'] ?? 30;

        try {
            $response = $this->httpClient->request($method, $url, $options);
            $statusCode = $response->getStatusCode();

            // DELETE requests may return 200 with empty body
            if ($method === 'DELETE' && $statusCode >= 200 && $statusCode < 300) {
                return [];
            }

            $content = $response->getContent();

            if (empty($content)) {
                return [];
            }

            return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (ExceptionInterface $e) {
            $this->logger->error('Radarr API request failed', [
                'instance' => $instance->getName(),
                'method' => $method,
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                sprintf('Radarr API error: %s', $e->getMessage()),
                0,
                $e
            );
        }
    }
}
