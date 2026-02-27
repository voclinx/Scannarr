<?php

namespace App\Controller;

use App\Repository\SettingRepository;
use App\Service\QBittorrentService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[Route('/api/v1/settings')]
#[IsGranted('ROLE_ADMIN')]
class SettingsController extends AbstractController
{
    /** @var string[] Keys that are allowed to be stored/updated */
    private const array ALLOWED_KEYS = [
        'tmdb_api_key',
        'discord_webhook_url',
        'discord_reminder_days',
        'qbittorrent_url',
        'qbittorrent_username',
        'qbittorrent_password',
    ];

    /** @var array<string, string> Key => type mapping */
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

    #[Route('', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $settings = $this->settingRepository->getAllAsArray();

        // Mask sensitive values
        $masked = $settings;
        if (isset($masked['qbittorrent_password']) && $masked['qbittorrent_password'] !== null) {
            $masked['qbittorrent_password'] = '••••••••';
        }

        return $this->json(['data' => $masked]);
    }

    #[Route('', methods: ['PUT'])]
    public function update(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        if (!$payload || !is_array($payload)) {
            return $this->json(
                ['error' => ['code' => 400, 'message' => 'Invalid JSON']],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $updatedKeys = [];

        foreach ($payload as $key => $value) {
            if (!in_array($key, self::ALLOWED_KEYS, true)) {
                continue;
            }

            $type = self::KEY_TYPES[$key] ?? 'string';
            $stringValue = $value !== null ? (string)$value : null;

            $this->settingRepository->setValue($key, $stringValue, $type);
            $updatedKeys[] = $key;
        }

        if ($updatedKeys === []) {
            return $this->json(
                ['error' => ['code' => 422, 'message' => 'No valid settings keys provided']],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $this->json([
            'data' => [
                'message' => 'Settings updated successfully',
                'updated_keys' => $updatedKeys,
            ],
        ]);
    }

    #[Route('/qbittorrent/test', methods: ['POST'])]
    public function testQBittorrentConnection(): JsonResponse
    {
        $result = $this->qBittorrentService->testConnection();

        if ($result['success']) {
            return $this->json(['data' => $result]);
        }

        return $this->json([
            'error' => [
                'code' => 400,
                'message' => sprintf('Connection failed: %s', $result['error'] ?? 'Unknown error'),
            ],
        ], Response::HTTP_BAD_REQUEST);
    }

    /**
     * POST /api/v1/settings/test-discord — Test Discord webhook from backend (avoids CORS).
     */
    #[Route('/test-discord', methods: ['POST'])]
    public function testDiscordWebhook(): JsonResponse
    {
        $webhookUrl = $this->settingRepository->getValue('discord_webhook_url');

        if (!$webhookUrl) {
            return $this->json(
                ['error' => ['code' => 422, 'message' => 'Discord webhook URL is not configured']],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            $response = $this->httpClient->request('POST', $webhookUrl, [
                'json' => [
                    'embeds' => [
                        [
                            'title' => '🔔 Test Scanarr',
                            'description' => 'Les notifications Discord fonctionnent correctement !',
                            'color' => 3066993,
                            'footer' => ['text' => 'Scanarr — Test de notification'],
                            'timestamp' => (new \DateTimeImmutable())->format('c'),
                        ],
                    ],
                ],
                'timeout' => 10,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 300) {
                return $this->json([
                    'data' => [
                        'success' => true,
                        'message' => 'Notification envoyée avec succès',
                    ],
                ]);
            }

            return $this->json([
                'error' => [
                    'code' => 400,
                    'message' => sprintf('Discord returned HTTP %d', $statusCode),
                ],
            ], Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->warning('Discord webhook test failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'error' => [
                    'code' => 400,
                    'message' => sprintf('Discord webhook test failed: %s', $e->getMessage()),
                ],
            ], Response::HTTP_BAD_REQUEST);
        }
    }
}
