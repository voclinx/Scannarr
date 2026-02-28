<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\SettingsService;
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
    public function __construct(private readonly SettingsService $settingsService)
    {
    }

    #[Route('', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json(['data' => $this->settingsService->list()]);
    }

    #[Route('', methods: ['PUT'])]
    public function update(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!$data || !is_array($data)) {
            return $this->json(['error' => ['code' => 400, 'message' => 'Invalid JSON']], Response::HTTP_BAD_REQUEST);
        }

        $result = $this->settingsService->update($data);
        if ($result['result'] === 'no_valid_keys') {
            return $this->json(['error' => ['code' => 422, 'message' => 'No valid settings keys provided']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['data' => ['message' => 'Settings updated successfully', 'updated_keys' => $result['updated_keys']]]);
    }

    #[Route('/qbittorrent/test', methods: ['POST'])]
    public function testQBittorrentConnection(): JsonResponse
    {
        $result = $this->settingsService->testQBittorrentConnection();
        if ($result['success']) {
            return $this->json(['data' => $result]);
        }

        return $this->json(['error' => ['code' => 400, 'message' => sprintf('Connection failed: %s', $result['error'] ?? 'Unknown error')]], Response::HTTP_BAD_REQUEST);
    }

    #[Route('/test-discord', methods: ['POST'])]
    public function testDiscordWebhook(): JsonResponse
    {
        $result = $this->settingsService->testDiscordWebhook();

        if (!$result['success']) {
            $code = ($result['code'] ?? null) === 422 ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_BAD_REQUEST;

            return $this->json(['error' => ['code' => 400, 'message' => $result['error']]], $code);
        }

        return $this->json(['data' => ['success' => true, 'message' => $result['message']]]);
    }
}
