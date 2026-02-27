<?php

namespace App\Controller;

use App\Entity\ActivityLog;
use App\Entity\MediaFile;
use App\Entity\ScheduledDeletion;
use App\Entity\ScheduledDeletionItem;
use App\Entity\User;
use App\Enum\DeletionStatus;
use App\Repository\MediaFileRepository;
use App\Security\Voter\FileVoter;
use App\Service\DeletionService;
use App\Service\RadarrService;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/files')]
class FileController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaFileRepository $mediaFileRepository,
        private readonly RadarrService $radarrService,
        private readonly DeletionService $deletionService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * List files with search, filters and pagination — accessible to Guest+.
     */
    #[Route('', methods: ['GET'])]
    #[IsGranted('ROLE_GUEST')]
    public function index(Request $request): JsonResponse
    {
        $page = max(1, (int)$request->query->get('page', 1));
        $limit = min(100, max(1, (int)$request->query->get('limit', 25)));
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
                ->setParameter('minHardlinks', (int)$minHardlinks);
        }

        // Count total before pagination
        $countQb = clone $qb;
        $countQb->select('COUNT(mf.id)');
        $total = (int)$countQb->getQuery()->getSingleScalarResult();

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

        $data = array_map($this->serializeFile(...), $files);

        return $this->json([
            'data' => $data,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    }

    /**
     * Get file details — Guest+.
     */
    #[Route('/{id}', methods: ['GET'])]
    #[IsGranted('ROLE_GUEST')]
    public function show(string $id): JsonResponse
    {
        $file = $this->mediaFileRepository->find($id);
        if (!$file instanceof MediaFile) {
            return $this->json([
                'error' => ['code' => 404, 'message' => 'File not found'],
            ], 404);
        }

        return $this->json(['data' => $this->serializeFile($file)]);
    }

    /**
     * Delete a file — AdvancedUser+.
     * Body: { "delete_physical": true, "delete_radarr_reference": true }
     *
     * Creates an ephemeral ScheduledDeletion and delegates to the watcher for physical deletion.
     */
    #[Route('/{id}', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADVANCED_USER')]
    public function delete(string $id, Request $request): JsonResponse
    {
        $file = $this->mediaFileRepository->find($id);
        if (!$file instanceof MediaFile) {
            return $this->json([
                'error' => ['code' => 404, 'message' => 'File not found'],
            ], 404);
        }

        $this->denyAccessUnlessGranted(FileVoter::DELETE, $file);

        $data = json_decode($request->getContent(), true) ?? [];
        $deletePhysical = (bool)($data['delete_physical'] ?? false);
        $deleteRadarrRef = (bool)($data['delete_radarr_reference'] ?? false);

        /** @var User $user */
        $user = $this->getUser();

        // Find the linked movie (for the ScheduledDeletionItem)
        $movie = null;
        foreach ($file->getMovieFiles() as $mf) {
            if ($mf->getMovie() !== null) {
                $movie = $mf->getMovie();
                break;
            }
        }

        // Create ephemeral ScheduledDeletion
        $deletion = new ScheduledDeletion();
        $deletion->setCreatedBy($user);
        $deletion->setScheduledDate(new DateTime('today'));
        $deletion->setDeletePhysicalFiles($deletePhysical);
        $deletion->setDeleteRadarrReference($deleteRadarrRef);

        if ($movie !== null) {
            $item = new ScheduledDeletionItem();
            $item->setMovie($movie);
            $item->setMediaFileIds([(string)$file->getId()]);
            $deletion->addItem($item);
        }

        $this->em->persist($deletion);
        $this->em->flush();

        // Execute through the standard pipeline
        $this->deletionService->executeDeletion($deletion);

        // Log the deletion
        $log = new ActivityLog();
        $log->setAction('file.deleted');
        $log->setEntityType('MediaFile');
        $log->setEntityId($file->getId());
        $log->setUser($user);
        $log->setDetails([
            'file_name' => $file->getFileName(),
            'file_path' => $file->getFilePath(),
            'volume' => $file->getVolume()?->getName(),
            'deletion_id' => (string)$deletion->getId(),
            'status' => $deletion->getStatus()->value,
        ]);
        $this->em->persist($log);
        $this->em->flush();

        $status = $deletion->getStatus();
        $httpCode = match ($status) {
            DeletionStatus::EXECUTING => Response::HTTP_ACCEPTED,
            DeletionStatus::WAITING_WATCHER => Response::HTTP_ACCEPTED,
            DeletionStatus::COMPLETED => Response::HTTP_OK,
            default => Response::HTTP_OK,
        };

        return $this->json([
            'data' => [
                'message' => 'Deletion initiated',
                'deletion_id' => (string)$deletion->getId(),
                'status' => $status->value,
            ],
        ], $httpCode);
    }

    /**
     * Global delete — removes a file across ALL volumes (by file name).
     * Body: { "delete_physical": true, "delete_radarr_reference": true, "disable_radarr_auto_search": false }
     *
     * Creates an ephemeral ScheduledDeletion and delegates to the watcher for physical deletion.
     */
    #[Route('/{id}/global', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADVANCED_USER')]
    public function globalDelete(string $id, Request $request): JsonResponse
    {
        $sourceFile = $this->mediaFileRepository->find($id);
        if (!$sourceFile instanceof MediaFile) {
            return $this->json([
                'error' => ['code' => 404, 'message' => 'File not found'],
            ], 404);
        }

        $this->denyAccessUnlessGranted(FileVoter::DELETE, $sourceFile);

        $data = json_decode($request->getContent(), true) ?? [];
        $deletePhysical = (bool)($data['delete_physical'] ?? false);
        $deleteRadarrRef = (bool)($data['delete_radarr_reference'] ?? false);
        $disableRadarrAutoSearch = (bool)($data['disable_radarr_auto_search'] ?? false);

        /** @var User $user */
        $user = $this->getUser();

        // Find all files with the same name across all volumes
        $allFiles = $this->mediaFileRepository->findByFileName($sourceFile->getFileName());

        // Collect file IDs grouped by movie
        $movieFiles = []; // movieId => [movie, file_ids]
        $warning = null;

        foreach ($allFiles as $file) {
            $movie = null;
            foreach ($file->getMovieFiles() as $mf) {
                if ($mf->getMovie() !== null) {
                    $movie = $mf->getMovie();
                    break;
                }
            }

            $movieId = $movie !== null ? (string)$movie->getId() : '__no_movie__';
            if (!isset($movieFiles[$movieId])) {
                $movieFiles[$movieId] = ['movie' => $movie, 'file_ids' => []];
            }
            $movieFiles[$movieId]['file_ids'][] = (string)$file->getId();

            // Warning check (independent of Radarr dereference)
            if ($movie !== null && !$disableRadarrAutoSearch && !$deleteRadarrRef && $movie->isRadarrMonitored()) {
                $warning = 'Radarr auto-search is still enabled for this movie. It may be re-downloaded.';
            }
        }

        // Create ephemeral ScheduledDeletion
        $deletion = new ScheduledDeletion();
        $deletion->setCreatedBy($user);
        $deletion->setScheduledDate(new DateTime('today'));
        $deletion->setDeletePhysicalFiles($deletePhysical);
        $deletion->setDeleteRadarrReference($deleteRadarrRef);
        $deletion->setDisableRadarrAutoSearch($disableRadarrAutoSearch);

        foreach ($movieFiles as $movieData) {
            $movie = $movieData['movie'];
            if ($movie === null) {
                continue;
            }

            $item = new ScheduledDeletionItem();
            $item->setMovie($movie);
            $item->setMediaFileIds($movieData['file_ids']);
            $deletion->addItem($item);
        }

        $this->em->persist($deletion);
        $this->em->flush();

        // Execute through the standard pipeline
        $this->deletionService->executeDeletion($deletion);

        // Log each file deletion
        foreach ($allFiles as $file) {
            $log = new ActivityLog();
            $log->setAction('file.global_deleted');
            $log->setEntityType('MediaFile');
            $log->setEntityId($file->getId());
            $log->setUser($user);
            $log->setDetails([
                'file_name' => $file->getFileName(),
                'file_path' => $file->getFilePath(),
                'volume' => $file->getVolume()?->getName(),
                'global_source_id' => (string)$sourceFile->getId(),
                'deletion_id' => (string)$deletion->getId(),
            ]);
            $this->em->persist($log);
        }
        $this->em->flush();

        $status = $deletion->getStatus();
        $httpCode = match ($status) {
            DeletionStatus::EXECUTING => Response::HTTP_ACCEPTED,
            DeletionStatus::WAITING_WATCHER => Response::HTTP_ACCEPTED,
            DeletionStatus::COMPLETED => Response::HTTP_OK,
            default => Response::HTTP_OK,
        };

        $response = [
            'data' => [
                'message' => 'Global deletion initiated',
                'deletion_id' => (string)$deletion->getId(),
                'status' => $status->value,
                'files_count' => count($allFiles),
            ],
        ];

        if ($warning !== null) {
            $response['data']['warning'] = $warning;
        }

        return $this->json($response, $httpCode);
    }

    private function serializeFile(MediaFile $file): array
    {
        return [
            'id' => (string)$file->getId(),
            'volume_id' => (string)$file->getVolume()->getId(),
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
