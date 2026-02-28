<?php

declare(strict_types=1);

namespace App\Service;

use App\ExternalService\TorrentClient\QBittorrentService;
use App\Repository\SettingRepository;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final class SettingsService
{
    private const array ALLOWED_KEYS = [
        'tmdb_api_key',
        'discord_webhook_url',
        'discord_reminder_days',
        'qbittorrent_url',
        'qbittorrent_username',
        'qbittorrent_password',
    ];

    private const array KEY_TYPES = [
        'tmdb_api_key' => 'string',
        'discord_webhook_url' => 'string',
        'discord_reminder_days' => 'integer',
        'qbittorrent_url' => 'string',
        'qbittorrent_username' => 'string',
        'qbittorrent_password' => 'string',
    ];

    public function __construct(
        private readonly SettingRepository $settingRepository,
        private readonly QBittorrentService $qBittorrentService,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array<string, mixed> */
    public function list(): array
    {
        $settings = $this->settingRepository->getAllAsArray();
        if (isset($settings['qbittorrent_password']) && $settings['qbittorrent_password'] !== null) {
            $settings['qbittorrent_password'] = '••••••••';
        }

        return $settings;
    }

    /**
     * Update allowed settings.
     *
     * @param array<string, mixed> $data
     *
     * @return array{result: string, updated_keys?: string[]}
     */
    public function update(array $data): array
    {
        $updatedKeys = [];

        foreach ($data as $key => $value) {
            if (!in_array($key, self::ALLOWED_KEYS, true)) {
                continue;
            }
            $type = self::KEY_TYPES[$key] ?? 'string';
            $stringValue = $value !== null ? (string)$value : null;
            $this->settingRepository->setValue($key, $stringValue, $type);
            $updatedKeys[] = $key;
        }

        if ($updatedKeys === []) {
            return ['result' => 'no_valid_keys'];
        }

        return ['result' => 'updated', 'updated_keys' => $updatedKeys];
    }

    /** @return array<string, mixed> */
    public function testQBittorrentConnection(): array
    {
        return $this->qBittorrentService->testConnection();
    }

    /** @return array{success: bool, message?: string, error?: string} */
    public function testDiscordWebhook(): array
    {
        $webhookUrl = $this->settingRepository->getValue('discord_webhook_url');
        if (!$webhookUrl) {
            return ['success' => false, 'error' => 'Discord webhook URL is not configured', 'code' => 422];
        }

        try {
            $response = $this->httpClient->request('POST', $webhookUrl, [
                'json' => [
                    'embeds' => [[
                        'title' => '🔔 Test Scanarr',
                        'description' => 'Les notifications Discord fonctionnent correctement !',
                        'color' => 3066993,
                        'footer' => ['text' => 'Scanarr — Test de notification'],
                        'timestamp' => (new DateTimeImmutable())->format('c'),
                    ]],
                ],
                'timeout' => 10,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 200 && $statusCode < 300) {
                return ['success' => true, 'message' => 'Notification envoyée avec succès'];
            }

            return ['success' => false, 'error' => sprintf('Discord returned HTTP %d', $statusCode)];
        } catch (Throwable $e) {
            $this->logger->warning('Discord webhook test failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => sprintf('Discord webhook test failed: %s', $e->getMessage())];
        }
    }
}
