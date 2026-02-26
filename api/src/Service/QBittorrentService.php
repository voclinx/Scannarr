<?php

namespace App\Service;

use App\Repository\SettingRepository;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class QBittorrentService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly SettingRepository $settingRepository,
    ) {
    }

    /**
     * Test connection to a qBittorrent instance using stored settings.
     *
     * @return array{success: bool, version?: string, error?: string}
     */
    public function testConnection(): array
    {
        $url = $this->settingRepository->getValue('qbittorrent_url');
        $username = $this->settingRepository->getValue('qbittorrent_username');
        $password = $this->settingRepository->getValue('qbittorrent_password');

        if (!$url) {
            return [
                'success' => false,
                'error' => 'qBittorrent URL is not configured',
            ];
        }

        try {
            $baseUrl = rtrim($url, '/');

            // Step 1: Authenticate to get SID cookie
            $loginResponse = $this->httpClient->request('POST', $baseUrl . '/api/v2/auth/login', [
                'body' => [
                    'username' => $username ?? '',
                    'password' => $password ?? '',
                ],
                'timeout' => 10,
            ]);

            $loginBody = $loginResponse->getContent();
            if ($loginBody !== 'Ok.') {
                return [
                    'success' => false,
                    'error' => 'Authentication failed: invalid credentials',
                ];
            }

            // Extract SID cookie from response headers
            $cookies = $loginResponse->getHeaders()['set-cookie'] ?? [];
            $sid = null;
            foreach ($cookies as $cookie) {
                if (str_starts_with($cookie, 'SID=')) {
                    $sid = explode(';', substr($cookie, 4))[0];
                    break;
                }
            }

            if (!$sid) {
                return [
                    'success' => false,
                    'error' => 'Authentication succeeded but no session cookie received',
                ];
            }

            // Step 2: Get app version
            $versionResponse = $this->httpClient->request('GET', $baseUrl . '/api/v2/app/version', [
                'headers' => [
                    'Cookie' => 'SID=' . $sid,
                ],
                'timeout' => 10,
            ]);

            $version = $versionResponse->getContent();

            return [
                'success' => true,
                'version' => $version,
            ];
        } catch (Exception $e) {
            $this->logger->error('qBittorrent connection test failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
