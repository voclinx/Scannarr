<?php

declare(strict_types=1);

namespace App\ExternalService\TorrentClient;

use App\Contract\TorrentClient\TorrentClientInterface;
use App\Repository\SettingRepository;
use JsonException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

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
            return [
                'success' => false,
                'error' => 'qBittorrent URL is not configured',
            ];
        }

        try {
            $sid = $this->getSid();
            $baseUrl = rtrim($this->settingRepository->getValue('qbittorrent_url') ?? '', '/');

            $versionResponse = $this->httpClient->request('GET', $baseUrl . '/api/v2/app/version', [
                'headers' => ['Cookie' => 'SID=' . $sid],
                'timeout' => 10,
            ]);

            return [
                'success' => true,
                'version' => $versionResponse->getContent(),
            ];
        } catch (Throwable $e) {
            $this->logger->error('qBittorrent connection test failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get all torrents from qBittorrent.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllTorrents(): array
    {
        $sid = $this->getSid();
        $baseUrl = rtrim($this->settingRepository->getValue('qbittorrent_url') ?? '', '/');

        try {
            $response = $this->httpClient->request('GET', $baseUrl . '/api/v2/torrents/info', [
                'headers' => ['Cookie' => 'SID=' . $sid],
                'timeout' => 30,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode === 403) {
                $this->cachedSid = null;
                $sid = $this->getSid();

                $response = $this->httpClient->request('GET', $baseUrl . '/api/v2/torrents/info', [
                    'headers' => ['Cookie' => 'SID=' . $sid],
                    'timeout' => 30,
                ]);
            }

            return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $this->logger->error('Failed to fetch all torrents from qBittorrent', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
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
            $normalizedFilePath = rtrim($absoluteFilePath, '/');

            foreach ($torrents as $torrent) {
                $contentPath = rtrim($torrent['content_path'] ?? '', '/');

                if ($contentPath === '') {
                    continue;
                }

                // Exact match (single-file torrent)
                if ($contentPath === $normalizedFilePath) {
                    $this->logger->info('Found torrent by exact match', [
                        'hash' => $torrent['hash'],
                        'name' => $torrent['name'],
                        'file' => $absoluteFilePath,
                    ]);

                    return $torrent;
                }

                // Prefix match (multi-file torrent: file is inside content_path directory)
                if (str_starts_with($normalizedFilePath, $contentPath . '/')) {
                    $this->logger->info('Found torrent by prefix match', [
                        'hash' => $torrent['hash'],
                        'name' => $torrent['name'],
                        'content_path' => $contentPath,
                        'file' => $absoluteFilePath,
                    ]);

                    return $torrent;
                }
            }

            $this->logger->debug('No torrent found for file', ['file' => $absoluteFilePath]);

            return null;
        } catch (Throwable $e) {
            $this->logger->warning('Failed to search for torrent', [
                'file' => $absoluteFilePath,
                'error' => $e->getMessage(),
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

        try {
            $sid = $this->getSid();
            $baseUrl = rtrim($this->settingRepository->getValue('qbittorrent_url') ?? '', '/');

            $this->httpClient->request('POST', $baseUrl . '/api/v2/torrents/delete', [
                'headers' => ['Cookie' => 'SID=' . $sid],
                'body' => [
                    'hashes' => $hash,
                    'deleteFiles' => $deleteFiles ? 'true' : 'false',
                ],
                'timeout' => 10,
            ]);

            $this->logger->info('Torrent deleted from qBittorrent', ['hash' => $hash]);

            return true;
        } catch (Throwable $e) {
            $this->logger->warning('Failed to delete torrent', [
                'hash' => $hash,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
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

        try {
            $sid = $this->getSid();
            $baseUrl = rtrim($this->settingRepository->getValue('qbittorrent_url') ?? '', '/');

            $this->httpClient->request('POST', $baseUrl . '/api/v2/torrents/delete', [
                'headers' => ['Cookie' => 'SID=' . $sid],
                'body' => [
                    'hashes' => implode('|', $hashes),
                    'deleteFiles' => $deleteFiles ? 'true' : 'false',
                ],
                'timeout' => 10,
            ]);

            $this->logger->info('Torrents deleted from qBittorrent', [
                'count' => count($hashes),
                'hashes' => $hashes,
            ]);

            return true;
        } catch (Throwable $e) {
            $this->logger->warning('Failed to delete torrents', [
                'hashes' => $hashes,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
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
        $baseUrl = rtrim($this->settingRepository->getValue('qbittorrent_url') ?? '', '/');

        $response = $this->httpClient->request('GET', $baseUrl . '/api/v2/torrents/files', [
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
            $qbitPrefix = $mapping['qbit'] ?? '';
            $hostPrefix = $mapping['host'] ?? '';

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
        $url = $this->settingRepository->getValue('qbittorrent_url');
        $username = $this->settingRepository->getValue('qbittorrent_username');
        $password = $this->settingRepository->getValue('qbittorrent_password');

        $baseUrl = rtrim($url ?? '', '/');

        $loginResponse = $this->httpClient->request('POST', $baseUrl . '/api/v2/auth/login', [
            'body' => [
                'username' => $username ?? '',
                'password' => $password ?? '',
            ],
            'timeout' => 10,
        ]);

        $loginBody = $loginResponse->getContent();
        if ($loginBody !== 'Ok.') {
            throw new RuntimeException('qBittorrent authentication failed: invalid credentials');
        }

        $cookies = $loginResponse->getHeaders()['set-cookie'] ?? [];
        $sid = null;
        foreach ($cookies as $cookie) {
            if (str_starts_with($cookie, 'SID=')) {
                $sid = explode(';', substr($cookie, 4))[0];
                break;
            }
        }

        if (!$sid) {
            throw new RuntimeException('qBittorrent authentication succeeded but no session cookie received');
        }

        return $sid;
    }
}
