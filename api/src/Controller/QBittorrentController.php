<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\SettingRepository;
use App\Service\QBittorrentSyncService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/qbittorrent')]
#[IsGranted('ROLE_ADMIN')]
class QBittorrentController extends AbstractController
{
    #[Route('/sync', methods: ['POST'])]
    public function sync(QBittorrentSyncService $syncService): JsonResponse
    {
        $result = $syncService->sync();

        return $this->json(['data' => $result]);
    }

    #[Route('/sync/status', methods: ['GET'])]
    public function syncStatus(SettingRepository $settingRepository): JsonResponse
    {
        return $this->json(['data' => [
            'last_sync_at' => $settingRepository->getValue('qbittorrent_last_sync_at'),
            'last_result' => json_decode($settingRepository->getValue('qbittorrent_last_sync_result') ?? '{}', true),
        ]]);
    }
}
