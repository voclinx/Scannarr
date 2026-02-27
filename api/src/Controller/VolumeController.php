<?php

namespace App\Controller;

use App\Enum\VolumeStatus;
use App\Repository\MediaFileRepository;
use App\Repository\VolumeRepository;
use App\Service\WatcherCommandService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/volumes')]
class VolumeController extends AbstractController
{
    public function __construct(
        private readonly VolumeRepository $volumeRepository,
        private readonly MediaFileRepository $mediaFileRepository,
        private readonly WatcherCommandService $watcherCommandService,
    ) {
    }

    /**
     * List all volumes — accessible to any authenticated user (Guest+).
     */
    #[Route('', methods: ['GET'])]
    #[IsGranted('ROLE_GUEST')]
    public function index(): JsonResponse
    {
        $volumes = $this->volumeRepository->findBy([], ['name' => 'ASC']);

        $data = array_map($this->serializeVolume(...), $volumes);

        return $this->json(['data' => $data]);
    }

    /**
     * Trigger a scan of the volume — Admin only.
     * Returns 202 (Accepted) since the scan runs asynchronously.
     */
    #[Route('/{id}/scan', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function scan(string $id): JsonResponse
    {
        $volume = $this->volumeRepository->find($id);
        if ($volume === null) {
            return $this->json([
                'error' => ['code' => 404, 'message' => 'Volume not found'],
            ], 404);
        }

        if ($volume->getStatus() !== VolumeStatus::ACTIVE) {
            return $this->json([
                'error' => ['code' => 422, 'message' => 'Cannot scan an inactive or errored volume'],
            ], 422);
        }

        $scanId = $this->watcherCommandService->requestScan($volume->getHostPath());

        return $this->json([
            'data' => [
                'message' => "Scan initiated for volume '{$volume->getName()}'",
                'volume_id' => (string)$volume->getId(),
                'scan_id' => $scanId,
            ],
        ], 202);
    }

    private function serializeVolume(\App\Entity\Volume $volume): array
    {
        return [
            'id' => (string)$volume->getId(),
            'name' => $volume->getName(),
            'path' => $volume->getPath(),
            'host_path' => $volume->getHostPath(),
            'type' => $volume->getType()->value,
            'status' => $volume->getStatus()->value,
            'total_space_bytes' => $volume->getTotalSpaceBytes(),
            'used_space_bytes' => $volume->getUsedSpaceBytes(),
            'last_scan_at' => $volume->getLastScanAt()?->format('c'),
            'file_count' => $this->mediaFileRepository->countByVolume($volume),
            'created_at' => $volume->getCreatedAt()->format('c'),
            'updated_at' => $volume->getUpdatedAt()->format('c'),
        ];
    }
}
