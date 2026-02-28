<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\VolumeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/volumes')]
class VolumeController extends AbstractController
{
    public function __construct(private readonly VolumeService $volumeService)
    {
    }

    #[Route('', methods: ['GET'])]
    #[IsGranted('ROLE_GUEST')]
    public function index(): JsonResponse
    {
        return $this->json(['data' => $this->volumeService->list()]);
    }

    #[Route('/{id}/scan', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function scan(string $id): JsonResponse
    {
        $result = $this->volumeService->scan($id);

        if ($result['status'] === 'not_found') {
            return $this->json(['error' => ['code' => 404, 'message' => 'Volume not found']], 404);
        }

        if ($result['status'] === 'inactive') {
            return $this->json(['error' => ['code' => 422, 'message' => $result['message']]], 422);
        }

        return $this->json([
            'data' => [
                'message' => $result['message'],
                'volume_id' => $result['volume_id'],
                'scan_id' => $result['scan_id'],
            ],
        ], 202);
    }
}
