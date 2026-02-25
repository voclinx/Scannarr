<?php

namespace App\Controller;

use App\Entity\ActivityLog;
use App\Entity\MediaFile;
use App\Repository\MediaFileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/files')]
class FileController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private MediaFileRepository $mediaFileRepository,
    ) {}

    /**
     * List files with search, filters and pagination — accessible to Guest+.
     *
     * Query params:
     *   - volume_id: filter by volume UUID
     *   - search: search in file_name and file_path
     *   - sort: sort field (file_name, file_size_bytes, detected_at, hardlink_count) default: detected_at
     *   - order: ASC or DESC (default: DESC)
     *   - page: page number (default: 1)
     *   - limit: items per page (default: 25, max: 100)
     *   - is_linked_radarr: filter by radarr link (true/false)
     *   - min_hardlinks: filter by minimum hardlink count
     */
    #[Route('', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 25)));
        $offset = ($page - 1) * $limit;

        $qb = $this->em->createQueryBuilder()
            ->select('mf', 'v')
            ->from(MediaFile::class, 'mf')
            ->leftJoin('mf.volume', 'v');

        // Filter by volume
        $volumeId = $request->query->get('volume_id');
        if ($volumeId) {
            $qb->andWhere('v.id = :volumeId')
                ->setParameter('volumeId', $volumeId);
        }

        // Search in file_name and file_path
        $search = $request->query->get('search');
        if ($search) {
            $qb->andWhere('LOWER(mf.fileName) LIKE :search OR LOWER(mf.filePath) LIKE :search')
                ->setParameter('search', '%' . strtolower($search) . '%');
        }

        // Filter by radarr link
        $isLinkedRadarr = $request->query->get('is_linked_radarr');
        if ($isLinkedRadarr !== null && $isLinkedRadarr !== '') {
            $qb->andWhere('mf.isLinkedRadarr = :linkedRadarr')
                ->setParameter('linkedRadarr', filter_var($isLinkedRadarr, FILTER_VALIDATE_BOOLEAN));
        }

        // Filter by minimum hardlinks
        $minHardlinks = $request->query->get('min_hardlinks');
        if ($minHardlinks !== null && $minHardlinks !== '') {
            $qb->andWhere('mf.hardlinkCount >= :minHardlinks')
                ->setParameter('minHardlinks', (int) $minHardlinks);
        }

        // Count total before pagination
        $countQb = clone $qb;
        $countQb->select('COUNT(mf.id)');
        $total = (int) $countQb->getQuery()->getSingleScalarResult();

        // Sort
        $allowedSorts = ['file_name', 'file_size_bytes', 'detected_at', 'hardlink_count', 'file_path'];
        $sortField = $request->query->get('sort', 'detected_at');
        $sortOrder = strtoupper($request->query->get('order', 'DESC'));

        if (!in_array($sortField, $allowedSorts, true)) {
            $sortField = 'detected_at';
        }
        if (!in_array($sortOrder, ['ASC', 'DESC'], true)) {
            $sortOrder = 'DESC';
        }

        // Map sort field to Doctrine property
        $sortMap = [
            'file_name' => 'mf.fileName',
            'file_size_bytes' => 'mf.fileSizeBytes',
            'detected_at' => 'mf.detectedAt',
            'hardlink_count' => 'mf.hardlinkCount',
            'file_path' => 'mf.filePath',
        ];

        $qb->orderBy($sortMap[$sortField], $sortOrder)
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        $files = $qb->getQuery()->getResult();

        $data = array_map(fn(MediaFile $f) => $this->serializeFile($f), $files);

        return $this->json([
            'data' => $data,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => (int) ceil($total / $limit),
            ],
        ]);
    }

    /**
     * Get file details — Guest+.
     */
    #[Route('/{id}', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $file = $this->mediaFileRepository->find($id);
        if (!$file) {
            return $this->json([
                'error' => ['code' => 404, 'message' => 'File not found'],
            ], 404);
        }

        return $this->json(['data' => $this->serializeFile($file)]);
    }

    /**
     * Delete a file — AdvancedUser+.
     * Body: { "delete_physical": true, "delete_radarr_reference": true }
     */
    #[Route('/{id}', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADVANCED_USER')]
    public function delete(string $id, Request $request): JsonResponse
    {
        $file = $this->mediaFileRepository->find($id);
        if (!$file) {
            return $this->json([
                'error' => ['code' => 404, 'message' => 'File not found'],
            ], 404);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $deletePhysical = (bool) ($data['delete_physical'] ?? false);
        $deleteRadarrRef = (bool) ($data['delete_radarr_reference'] ?? false);

        $physicalDeleted = false;
        $radarrDereferenced = false;

        // Physical deletion
        if ($deletePhysical) {
            $volume = $file->getVolume();
            $fullPath = rtrim($volume->getPath(), '/') . '/' . $file->getFilePath();

            if (file_exists($fullPath)) {
                if (@unlink($fullPath)) {
                    $physicalDeleted = true;
                } else {
                    return $this->json([
                        'error' => ['code' => 500, 'message' => 'Failed to delete physical file'],
                    ], 500);
                }
            } else {
                // File already gone from disk — that's ok
                $physicalDeleted = true;
            }
        }

        // TODO: Radarr dereference (Phase 3)
        if ($deleteRadarrRef && $file->isLinkedRadarr()) {
            // Will be implemented in Phase 3 with RadarrService
            $radarrDereferenced = false;
        }

        // Log the deletion
        $log = new ActivityLog();
        $log->setAction('file.deleted');
        $log->setEntityType('MediaFile');
        $log->setEntityId($file->getId());
        $log->setUser($this->getUser());
        $log->setDetails([
            'file_name' => $file->getFileName(),
            'file_path' => $file->getFilePath(),
            'volume' => $file->getVolume()->getName(),
            'size_bytes' => $file->getFileSizeBytes(),
            'physical_deleted' => $physicalDeleted,
            'radarr_dereferenced' => $radarrDereferenced,
        ]);
        $this->em->persist($log);

        // Remove from DB
        $this->em->remove($file);
        $this->em->flush();

        return $this->json([
            'data' => [
                'message' => 'File deleted successfully',
                'physical_deleted' => $physicalDeleted,
                'radarr_dereferenced' => $radarrDereferenced,
            ],
        ]);
    }

    private function serializeFile(MediaFile $file): array
    {
        return [
            'id' => (string) $file->getId(),
            'volume_id' => (string) $file->getVolume()->getId(),
            'volume_name' => $file->getVolume()->getName(),
            'file_path' => $file->getFilePath(),
            'file_name' => $file->getFileName(),
            'file_size_bytes' => $file->getFileSizeBytes(),
            'hardlink_count' => $file->getHardlinkCount(),
            'resolution' => $file->getResolution(),
            'codec' => $file->getCodec(),
            'quality' => $file->getQuality(),
            'is_linked_radarr' => $file->isLinkedRadarr(),
            'is_linked_media_player' => $file->isLinkedMediaPlayer(),
            'detected_at' => $file->getDetectedAt()->format('c'),
            'updated_at' => $file->getUpdatedAt()->format('c'),
        ];
    }
}
