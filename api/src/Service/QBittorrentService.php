<?php

namespace App\Service;

use App\Repository\SettingRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

class QBittorrentService
{
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
            $sid = $this->authenticate();
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
            $sid = $this->authenticate();
            $baseUrl = rtrim($this->settingRepository->getValue('qbittorrent_url') ?? '', '/');

            $response = $this->httpClient->request('GET', $baseUrl . '/api/v2/torrents/info', [
                'headers' => ['Cookie' => 'SID=' . $sid],
                'timeout' => 30,
            ]);

            $torrents = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
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
     * @param string $hash        The torrent info hash
     * @param bool   $deleteFiles Whether to delete files on disk (always false — Scanarr manages physical deletion)
     */
    public function deleteTorrent(string $hash, bool $deleteFiles = false): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        try {
            $sid = $this->authenticate();
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
     * Authenticate with qBittorrent and return the SID cookie.
     *
     * @return string The SID session cookie
     *
     * @throws \RuntimeException If authentication fails
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
            throw new \RuntimeException('qBittorrent authentication failed: invalid credentials');
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
            throw new \RuntimeException('qBittorrent authentication succeeded but no session cookie received');
        }

        return $sid;
    }
}
