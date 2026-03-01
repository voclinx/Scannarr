<?php

declare(strict_types=1);

namespace App\ExternalService\Metadata;

use App\Contract\Metadata\MetadataProviderInterface;
use App\Repository\SettingRepository;
use Exception;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class TmdbService implements MetadataProviderInterface
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

        return array_filter([
            ...$this->extractTextFields($details),
            ...$this->extractImageUrls($details),
            ...$this->extractGenres($details),
            ...$this->extractRating($details),
            ...$this->extractRuntime($details),
            ...$this->extractYear($details),
        ]);
    }

    /**
     * Extract basic text fields (title, original_title, synopsis) from TMDB details.
     *
     * @param array<string, mixed> $details
     *
     * @return array<string, string>
     */
    private function extractTextFields(array $details): array
    {
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

        return $data;
    }

    /**
     * Extract poster and backdrop URLs from TMDB details.
     *
     * @param array<string, mixed> $details
     *
     * @return array<string, string>
     */
    private function extractImageUrls(array $details): array
    {
        $data = [];

        if (!empty($details['poster_path'])) {
            $data['poster_url'] = $this->getPosterUrl($details['poster_path']);
        }

        if (!empty($details['backdrop_path'])) {
            $data['backdrop_url'] = $this->getBackdropUrl($details['backdrop_path']);
        }

        return $data;
    }

    /**
     * Extract genre names as a comma-separated string from TMDB details.
     *
     * @param array<string, mixed> $details
     *
     * @return array<string, string>
     */
    private function extractGenres(array $details): array
    {
        if (empty($details['genres'])) {
            return [];
        }

        $genreNames = array_map(fn (array $genre): string => $genre['name'], $details['genres']);

        return ['genres' => implode(', ', $genreNames)];
    }

    /**
     * Extract vote average rating from TMDB details.
     *
     * @param array<string, mixed> $details
     *
     * @return array<string, float>
     */
    private function extractRating(array $details): array
    {
        if (!isset($details['vote_average']) || $details['vote_average'] <= 0) {
            return [];
        }

        return ['rating' => round($details['vote_average'], 1)];
    }

    /**
     * Extract runtime in minutes from TMDB details.
     *
     * @param array<string, mixed> $details
     *
     * @return array<string, int>
     */
    private function extractRuntime(array $details): array
    {
        if (empty($details['runtime'])) {
            return [];
        }

        return ['runtime_minutes' => (int)$details['runtime']];
    }

    /**
     * Extract release year from TMDB details.
     *
     * @param array<string, mixed> $details
     *
     * @return array<string, int>
     */
    private function extractYear(array $details): array
    {
        if (empty($details['release_date'])) {
            return [];
        }

        $year = (int)substr((string)$details['release_date'], 0, 4);

        if ($year <= 0) {
            return [];
        }

        return ['year' => $year];
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
        $url = self::BASE_URL . $endpoint;
        $options = $this->buildRequestOptions($options);

        try {
            $response = $this->httpClient->request($method, $url, $options);

            return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (ExceptionInterface $exception) {
            throw $this->handleRequestError($method, $endpoint, $exception);
        }
    }

    /**
     * Build request options with API key, headers and timeout.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function buildRequestOptions(array $options): array
    {
        $options['query'] = array_merge($options['query'] ?? [], [
            'api_key' => $this->getApiKey(),
        ]);

        $options['headers'] = ['Accept' => 'application/json'];
        $options['timeout'] ??= 15;

        return $options;
    }

    /**
     * Log and wrap an API exception into a RuntimeException.
     */
    private function handleRequestError(string $method, string $endpoint, ExceptionInterface $exception): RuntimeException
    {
        $this->logger->error('TMDB API request failed', [
            'method' => $method,
            'endpoint' => $endpoint,
            'error' => $exception->getMessage(),
        ]);

        return new RuntimeException(
            sprintf('TMDB API error: %s', $exception->getMessage()),
            0,
            $exception,
        );
    }
}
