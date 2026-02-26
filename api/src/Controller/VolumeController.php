<?php

namespace App\Controller;

use App\Entity\Volume;
use App\Enum\VolumeStatus;
use App\Enum\VolumeType;
use App\Repository\MediaFileRepository;
use App\Repository\VolumeRepository;
use App\Service\WatcherCommandService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Throwable;

#[Route('/api/v1/volumes')]
class VolumeController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly VolumeRepository $volumeRepository,
        private readonly MediaFileRepository $mediaFileRepository,
        private readonly WatcherCommandService $watcherCommandService,
    ) {
    }

    /**
     * List all volumes — accessible to any authenticated user (Guest+).
     */
    #[Route('', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $volumes = $this->volumeRepository->findBy([], ['name' => 'ASC']);

        $data = array_map($this->serializeVolume(...), $volumes);

        return $this->json(['data' => $data]);
    }

    /**
     * Create a new volume — Admin only.
     */
    #[Route('', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return $this->json([
                'error' => ['code' => 400, 'message' => 'Invalid JSON'],
            ], 400);
        }

        // Validate required fields
        $name = trim($data['name'] ?? '');
        $path = trim($data['path'] ?? '');
        $hostPath = trim($data['host_path'] ?? '');

        if ($name === '' || $path === '' || $hostPath === '') {
            return $this->json([
                'error' => ['code' => 422, 'message' => 'Validation failed', 'details' => [
                    'name' => $name === '' ? 'Name is required' : null,
                    'path' => $path === '' ? 'Path is required' : null,
                    'host_path' => $hostPath === '' ? 'Host path is required' : null,
                ]],
            ], 422);
        }

        // Validate type
        $typeStr = $data['type'] ?? 'local';
        $type = VolumeType::tryFrom($typeStr);
        if (!$type) {
            return $this->json([
                'error' => ['code' => 422, 'message' => 'Validation failed', 'details' => ['type' => 'Invalid volume type. Must be "local" or "network"']],
            ], 422);
        }

        // Check path is accessible inside the container
        if (!is_dir($path)) {
            return $this->json([
                'error' => ['code' => 422, 'message' => 'Path does not exist or is not accessible', 'details' => ['path' => "The path '{$path}' is not accessible in the container"]],
            ], 422);
        }

        $volume = new Volume();
        $volume->setName($name);
        $volume->setPath($path);
        $volume->setHostPath($hostPath);
        $volume->setType($type);
        $volume->setStatus(VolumeStatus::ACTIVE);

        // Try to get disk space info
        $totalSpace = @disk_total_space($path);
        $freeSpace = @disk_free_space($path);
        if ($totalSpace !== false) {
            $volume->setTotalSpaceBytes((int)$totalSpace);
        }
        if ($totalSpace !== false && $freeSpace !== false) {
            $volume->setUsedSpaceBytes((int)($totalSpace - $freeSpace));
        }

        $this->em->persist($volume);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            return $this->json([
                'error' => ['code' => 409, 'message' => 'A volume with this path already exists'],
            ], 409);
        }

        // Notify watcher to start watching this path
        try {
            $this->watcherCommandService->requestWatchAdd($hostPath);
        } catch (Throwable) {
            // Non-blocking — watcher might not be connected
        }

        return $this->json(['data' => $this->serializeVolume($volume)], 201);
    }

    /**
     * Update a volume — Admin only.
     */
    #[Route('/{id}', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function update(string $id, Request $request): JsonResponse
    {
        $volume = $this->volumeRepository->find($id);
        if (!$volume instanceof Volume) {
            return $this->json([
                'error' => ['code' => 404, 'message' => 'Volume not found'],
            ], 404);
        }

        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return $this->json([
                'error' => ['code' => 400, 'message' => 'Invalid JSON'],
            ], 400);
        }

        $oldHostPath = $volume->getHostPath();

        if (isset($data['name'])) {
            $volume->setName(trim((string)$data['name']));
        }
        if (isset($data['path'])) {
            $volume->setPath(trim((string)$data['path']));
        }
        if (isset($data['host_path'])) {
            $volume->setHostPath(trim((string)$data['host_path']));
        }
        if (isset($data['type'])) {
            $type = VolumeType::tryFrom($data['type']);
            if (!$type) {
                return $this->json([
                    'error' => ['code' => 422, 'message' => 'Validation failed', 'details' => ['type' => 'Invalid volume type']],
                ], 422);
            }
            $volume->setType($type);
        }
        if (isset($data['status'])) {
            $status = VolumeStatus::tryFrom($data['status']);
            if (!$status) {
                return $this->json([
                    'error' => ['code' => 422, 'message' => 'Validation failed', 'details' => ['status' => 'Invalid volume status']],
                ], 422);
            }
            $volume->setStatus($status);
        }

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            return $this->json([
                'error' => ['code' => 409, 'message' => 'A volume with this path already exists'],
            ], 409);
        }

        // If host_path changed, update watcher
        $newHostPath = $volume->getHostPath();
        if ($oldHostPath !== $newHostPath) {
            try {
                $this->watcherCommandService->requestWatchRemove($oldHostPath);
                $this->watcherCommandService->requestWatchAdd($newHostPath);
            } catch (Throwable) {
                // Non-blocking
            }
        }

        return $this->json(['data' => $this->serializeVolume($volume)]);
    }

    /**
     * Delete a volume — Admin only.
     */
    #[Route('/{id}', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(string $id): JsonResponse
    {
        $volume = $this->volumeRepository->find($id);
        if (!$volume instanceof Volume) {
            return $this->json([
                'error' => ['code' => 404, 'message' => 'Volume not found'],
            ], 404);
        }

        $hostPath = $volume->getHostPath();

        // CASCADE will remove associated MediaFiles
        $this->em->remove($volume);
        $this->em->flush();

        // Notify watcher to stop watching this path
        try {
            $this->watcherCommandService->requestWatchRemove($hostPath);
        } catch (Throwable) {
            // Non-blocking
        }

        return $this->json(null, 204);
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
        if (!$volume instanceof Volume) {
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

    private function serializeVolume(Volume $volume): array
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
