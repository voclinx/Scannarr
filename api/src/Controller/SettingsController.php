<?php

namespace App\Controller;

use App\Repository\SettingRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/settings')]
#[IsGranted('ROLE_ADMIN')]
class SettingsController extends AbstractController
{
    /** @var string[] Keys that are allowed to be stored/updated */
    private const ALLOWED_KEYS = [
        'tmdb_api_key',
        'discord_webhook_url',
        'discord_reminder_days',
        'qbittorrent_url',
        'qbittorrent_username',
        'qbittorrent_password',
    ];

    /** @var array<string, string> Key => type mapping */
    private const KEY_TYPES = [
        'tmdb_api_key' => 'string',
        'discord_webhook_url' => 'string',
        'discord_reminder_days' => 'integer',
        'qbittorrent_url' => 'string',
        'qbittorrent_username' => 'string',
        'qbittorrent_password' => 'string',
    ];

    public function __construct(
        private SettingRepository $settingRepository,
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
                Response::HTTP_BAD_REQUEST
            );
        }

        $updatedKeys = [];

        foreach ($payload as $key => $value) {
            if (!in_array($key, self::ALLOWED_KEYS, true)) {
                continue;
            }

            $type = self::KEY_TYPES[$key] ?? 'string';
            $stringValue = $value !== null ? (string) $value : null;

            $this->settingRepository->setValue($key, $stringValue, $type);
            $updatedKeys[] = $key;
        }

        if (empty($updatedKeys)) {
            return $this->json(
                ['error' => ['code' => 422, 'message' => 'No valid settings keys provided']],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return $this->json([
            'data' => [
                'message' => 'Settings updated successfully',
                'updated_keys' => $updatedKeys,
            ],
        ]);
    }
}
