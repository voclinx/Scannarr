<?php

declare(strict_types=1);

namespace App\ExternalService\TorrentClient;

use App\Contract\TorrentClient\TorrentClientInterface;
use App\Repository\SettingRepository;
use JsonException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

/** @SuppressWarnings(PHPMD.ExcessiveClassComplexity) */
class QBittorrentService implements TorrentClientInterface
{
    private ?string $cachedSid = null;
    private ?float $sidExpiry = null;
    private const int SID_TTL_SECONDS = 1800; // 30 minutes

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly SettingRepository $settingRepository,
    ) {
    }

    /**
     * Check if qBittorrent is configured in settings.
     */
    public function isConfigured(): bool
    {
        $url = $this->settingRepository->getValue('qbittorrent_url');

        return $url !== null && $url !== '';
    }

    /**
     * Test connection to a qBittorrent instance using stored settings.
     *
     * @return array{success: bool, version?: string, error?: string}
     */
    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'qBittorrent URL is not configured'];
        }

        try {
            $response = $this->authenticatedRequest('GET', '/api/v2/app/version', timeout: 10);

            return ['success' => true, 'version' => $response->getContent()];
        } catch (Throwable $exception) {
            $this->logger->error('qBittorrent connection test failed', ['error' => $exception->getMessage()]);

            return ['success' => false, 'error' => $exception->getMessage()];
        }
    }

    /**
     * Get all torrents from qBittorrent.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllTorrents(): array
    {
        try {
            $response = $this->authenticatedRequestWithRetry('GET', '/api/v2/torrents/info', timeout: 30);

            return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            $this->logger->error('Failed to fetch all torrents from qBittorrent', ['error' => $exception->getMessage()]);

            throw $exception;
        }
    }

    /**
     * Find a torrent by matching its content_path against a file path.
     *
     * @param string $absoluteFilePath The absolute host path to the file
     *
     * @return array<string, mixed>|null The matching torrent data or null
     */
    public function findTorrentByFilePath(string $absoluteFilePath): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        try {
            $torrents = $this->getAllTorrents();

            return $this->matchTorrentByPath($torrents, $absoluteFilePath);
        } catch (Throwable $exception) {
            $this->logger->warning('Failed to search for torrent', [
                'file' => $absoluteFilePath,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Delete a torrent by its hash.
     *
     * @param string $hash The torrent info hash
     * @param bool $deleteFiles Whether to delete files on disk (always false — Scanarr manages physical deletion)
     */
    public function deleteTorrent(string $hash, bool $deleteFiles = false): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        return $this->executeDeleteRequest($hash, $deleteFiles, ['hash' => $hash]);
    }

    /**
     * Delete multiple torrents by their hashes.
     *
     * @param array<string> $hashes The torrent info hashes
     * @param bool $deleteFiles Whether to delete files on disk
     */
    public function deleteTorrents(array $hashes, bool $deleteFiles = false): bool
    {
        if (!$this->isConfigured() || $hashes === []) {
            return false;
        }

        return $this->executeDeleteRequest(
            implode('|', $hashes),
            $deleteFiles,
            ['count' => count($hashes), 'hashes' => $hashes],
        );
    }

    /**
     * Find and delete a torrent associated with a file path.
     * Best-effort: returns false if not found or error, never throws.
     *
     * @param string $absoluteFilePath The absolute host path to the file
     */
    public function findAndDeleteTorrent(string $absoluteFilePath): bool
    {
        $torrent = $this->findTorrentByFilePath($absoluteFilePath);

        if ($torrent === null) {
            return false;
        }

        $hash = $torrent['hash'] ?? null;

        if ($hash === null) {
            $this->logger->warning('Torrent found but no hash available', [
                'file' => $absoluteFilePath,
            ]);

            return false;
        }

        return $this->deleteTorrent($hash, false);
    }

    /**
     * Get files for a specific torrent.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTorrentFiles(string $hash): array
    {
        $sid = $this->getSid();

        $response = $this->httpClient->request('GET', $this->getBaseUrl() . '/api/v2/torrents/files', [
            'headers' => ['Cookie' => 'SID=' . $sid],
            'query' => ['hash' => $hash],
            'timeout' => 10,
        ]);

        return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Get qBittorrent path mappings from settings.
     *
     * @return array<int, array{qbit: string, host: string}>
     */
    public function getPathMappings(): array
    {
        $json = $this->settingRepository->getValue('qbittorrent_path_mappings');

        if ($json === null || $json === '') {
            return [];
        }

        try {
            $mappings = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($mappings)) {
                return [];
            }

            return $mappings;
        } catch (JsonException) {
            $this->logger->warning('Invalid qBittorrent path mappings JSON', ['value' => $json]);

            return [];
        }
    }

    /**
     * Map a qBittorrent path to a host path using configured mappings.
     */
    public function mapQbitPathToHost(string $qbitPath): string
    {
        foreach ($this->getPathMappings() as $mapping) {
            $qbitPrefix = $mapping['qbit'];
            $hostPrefix = $mapping['host'];

            if ($qbitPrefix !== '' && str_starts_with($qbitPath, $qbitPrefix)) {
                return $hostPrefix . substr($qbitPath, strlen($qbitPrefix));
            }
        }

        return $qbitPath;
    }

    /**
     * Get a cached SID or authenticate fresh.
     *
     * @return string The SID session cookie
     *
     * @throws RuntimeException If authentication fails
     */
    private function getSid(): string
    {
        if ($this->cachedSid !== null && $this->sidExpiry !== null && microtime(true) < $this->sidExpiry) {
            return $this->cachedSid;
        }

        $sid = $this->authenticate();
        $this->cachedSid = $sid;
        $this->sidExpiry = microtime(true) + self::SID_TTL_SECONDS;

        return $sid;
    }

    /**
     * Authenticate with qBittorrent and return the SID cookie.
     *
     * @return string The SID session cookie
     *
     * @throws RuntimeException If authentication fails
     */
    private function authenticate(): string
    {
        $loginResponse = $this->sendLoginRequest();

        $loginBody = $loginResponse->getContent();
        if ($loginBody !== 'Ok.') {
            throw new RuntimeException('qBittorrent authentication failed: invalid credentials');
        }

        return $this->extractSidFromResponse($loginResponse);
    }

    /**
     * Send the login request to qBittorrent.
     */
    private function sendLoginRequest(): ResponseInterface
    {
        $baseUrl = $this->getBaseUrl();
        $username = $this->settingRepository->getValue('qbittorrent_username') ?? '';
        $password = $this->settingRepository->getValue('qbittorrent_password') ?? '';

        return $this->httpClient->request('POST', $baseUrl . '/api/v2/auth/login', [
            'body' => ['username' => $username, 'password' => $password],
            'timeout' => 10,
        ]);
    }

    /**
     * Extract the SID session cookie from a login response.
     *
     * @throws RuntimeException If no SID cookie is found
     */
    private function extractSidFromResponse(ResponseInterface $response): string
    {
        $cookies = $response->getHeaders()['set-cookie'] ?? [];

        foreach ($cookies as $cookie) {
            if (str_starts_with($cookie, 'SID=')) {
                return explode(';', substr($cookie, 4))[0];
            }
        }

        throw new RuntimeException('qBittorrent authentication succeeded but no session cookie received');
    }

    /**
     * @param array<int, array<string, mixed>> $torrents
     *
     * @return array<string, mixed>|null
     */
    private function matchTorrentByPath(array $torrents, string $absoluteFilePath): ?array
    {
        foreach ($torrents as $torrent) {
            $qbitPath = (string)($torrent['content_path'] ?? '');
            if ($qbitPath === '') {
                continue;
            }

            if ($this->mapQbitPathToHost($qbitPath) === $absoluteFilePath) {
                return $torrent;
            }
        }

        return null;
    }

    private function getBaseUrl(): string
    {
        return rtrim($this->settingRepository->getValue('qbittorrent_url') ?? '', '/');
    }

    private function authenticatedRequest(string $method, string $path, int $timeout = 30): ResponseInterface
    {
        $sid = $this->getSid();

        return $this->httpClient->request($method, $this->getBaseUrl() . $path, [
            'headers' => ['Cookie' => 'SID=' . $sid],
            'timeout' => $timeout,
        ]);
    }

    private function authenticatedRequestWithRetry(string $method, string $path, int $timeout = 30): ResponseInterface
    {
        try {
            return $this->authenticatedRequest($method, $path, $timeout);
        } catch (Throwable) {
            $this->cachedSid = null;
            $this->sidExpiry = null;

            return $this->authenticatedRequest($method, $path, $timeout);
        }
    }

    /** @param array<string, mixed> $logContext */
    private function executeDeleteRequest(string $hashes, bool $deleteFiles, array $logContext = []): bool
    {
        try {
            $sid = $this->getSid();
            $this->httpClient->request('POST', $this->getBaseUrl() . '/api/v2/torrents/delete', [
                'headers' => ['Cookie' => 'SID=' . $sid],
                'body' => [
                    'hashes' => $hashes,
                    'deleteFiles' => $deleteFiles ? 'true' : 'false',
                ],
                'timeout' => 10,
            ]);

            return true;
        } catch (Throwable $exception) {
            $this->logger->error('Failed to delete torrent(s) from qBittorrent', array_merge($logContext, [
                'error' => $exception->getMessage(),
            ]));

            return false;
        }
    }
}
