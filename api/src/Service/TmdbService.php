<?php

namespace App\Service;

use App\Repository\SettingRepository;
use Exception;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class TmdbService
{
    private const string BASE_URL = 'https://api.themoviedb.org/3';
    private const string IMAGE_BASE_URL = 'https://image.tmdb.org/t/p';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SettingRepository $settingRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Get movie details from TMDB.
     *
     * @return array{
     *     id: int,
     *     title: string,
     *     original_title: string,
     *     overview: string,
     *     release_date: string,
     *     poster_path: ?string,
     *     backdrop_path: ?string,
     *     genres: array<int, array{id: int, name: string}>,
     *     vote_average: float,
     *     runtime: ?int
     * }|null
     */
    public function getMovieDetails(int $tmdbId, string $language = 'fr-FR'): ?array
    {
        try {
            return $this->request('GET', "/movie/{$tmdbId}", [
                'query' => ['language' => $language],
            ]);
        } catch (Exception $e) {
            $this->logger->warning('TMDB getMovieDetails failed', [
                'tmdb_id' => $tmdbId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get movie images from TMDB (posters + backdrops).
     *
     * @return array{
     *     posters: array<int, array{file_path: string, width: int, height: int}>,
     *     backdrops: array<int, array{file_path: string, width: int, height: int}>
     * }|null
     */
    public function getMovieImages(int $tmdbId): ?array
    {
        try {
            return $this->request('GET', "/movie/{$tmdbId}/images");
        } catch (Exception $e) {
            $this->logger->warning('TMDB getMovieImages failed', [
                'tmdb_id' => $tmdbId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Search for a movie on TMDB by title and optional year.
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchMovie(string $title, ?int $year = null, string $language = 'fr-FR'): array
    {
        try {
            $query = [
                'query' => $title,
                'language' => $language,
            ];

            if ($year !== null) {
                $query['year'] = $year;
            }

            $result = $this->request('GET', '/search/movie', [
                'query' => $query,
            ]);

            return $result['results'] ?? [];
        } catch (Exception $e) {
            $this->logger->warning('TMDB searchMovie failed', [
                'title' => $title,
                'year' => $year,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Build a full poster URL from a TMDB poster path.
     */
    public function getPosterUrl(?string $posterPath, string $size = 'w500'): ?string
    {
        if ($posterPath === null) {
            return null;
        }

        return self::IMAGE_BASE_URL . '/' . $size . $posterPath;
    }

    /**
     * Build a full backdrop URL from a TMDB backdrop path.
     */
    public function getBackdropUrl(?string $backdropPath, string $size = 'original'): ?string
    {
        if ($backdropPath === null) {
            return null;
        }

        return self::IMAGE_BASE_URL . '/' . $size . $backdropPath;
    }

    /**
     * Enrich a movie entity data with TMDB info.
     * Returns extracted data suitable for updating a Movie entity.
     *
     * @return array{
     *     title?: string,
     *     original_title?: string,
     *     synopsis?: string,
     *     poster_url?: string,
     *     backdrop_url?: string,
     *     genres?: string,
     *     rating?: float,
     *     runtime_minutes?: int,
     *     year?: int
     * }
     */
    public function enrichMovieData(int $tmdbId): array
    {
        $details = $this->getMovieDetails($tmdbId);

        if ($details === null) {
            return [];
        }

        $data = [];

        if (!empty($details['title'])) {
            $data['title'] = $details['title'];
        }

        if (!empty($details['original_title'])) {
            $data['original_title'] = $details['original_title'];
        }

        if (!empty($details['overview'])) {
            $data['synopsis'] = $details['overview'];
        }

        if (!empty($details['poster_path'])) {
            $data['poster_url'] = $this->getPosterUrl($details['poster_path']);
        }

        if (!empty($details['backdrop_path'])) {
            $data['backdrop_url'] = $this->getBackdropUrl($details['backdrop_path']);
        }

        if (!empty($details['genres'])) {
            $genreNames = array_map(fn (array $g): string => $g['name'], $details['genres']);
            $data['genres'] = implode(', ', $genreNames);
        }

        if (isset($details['vote_average']) && $details['vote_average'] > 0) {
            $data['rating'] = round($details['vote_average'], 1);
        }

        if (!empty($details['runtime'])) {
            $data['runtime_minutes'] = (int)$details['runtime'];
        }

        if (!empty($details['release_date'])) {
            $year = (int)substr($details['release_date'], 0, 4);
            if ($year > 0) {
                $data['year'] = $year;
            }
        }

        return $data;
    }

    /**
     * Get the TMDB API key from settings.
     *
     * @throws RuntimeException If the API key is not configured
     */
    private function getApiKey(): string
    {
        $setting = $this->settingRepository->findOneBy(['settingKey' => 'tmdb_api_key']);

        if ($setting === null || empty($setting->getSettingValue())) {
            throw new RuntimeException('TMDB API key is not configured. Set it in Settings.');
        }

        return $setting->getSettingValue();
    }

    /**
     * Make an API request to TMDB.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    private function request(string $method, string $endpoint, array $options = []): array
    {
        $apiKey = $this->getApiKey();
        $url = self::BASE_URL . $endpoint;

        // Add API key as query parameter
        $options['query'] = array_merge($options['query'] ?? [], [
            'api_key' => $apiKey,
        ]);

        $options['headers'] = [
            'Accept' => 'application/json',
        ];

        $options['timeout'] ??= 15;

        try {
            $response = $this->httpClient->request($method, $url, $options);

            return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (ExceptionInterface $e) {
            $this->logger->error('TMDB API request failed', [
                'method' => $method,
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException(
                sprintf('TMDB API error: %s', $e->getMessage()),
                0,
                $e,
            );
        }
    }
}
