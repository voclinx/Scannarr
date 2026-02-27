<?php

namespace App\Controller;

use App\Entity\ActivityLog;
use App\Entity\Movie;
use App\Entity\RadarrInstance;
use App\Entity\ScheduledDeletion;
use App\Entity\ScheduledDeletionItem;
use App\Entity\User;
use App\Enum\DeletionStatus;
use App\Repository\MediaFileRepository;
use App\Repository\MovieFileRepository;
use App\Repository\MovieRepository;
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

#[Route('/api/v1/movies')]
class MovieController extends AbstractController
{
    public function __construct(
        private readonly MovieRepository $movieRepository,
        private readonly MovieFileRepository $movieFileRepository,
        private readonly MediaFileRepository $mediaFileRepository,
        private readonly EntityManagerInterface $em,
        private readonly RadarrService $radarrService,
        private readonly DeletionService $deletionService,
        private readonly LoggerInterface $logger,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * GET /api/v1/movies — List movies with search, filters, sort, pagination.
     */
    #[Route('', methods: ['GET'])]
    #[IsGranted('ROLE_GUEST')]
    public function list(Request $request): JsonResponse
    {
        $filters = [
            'search' => $request->query->get('search'),
            'sort' => $request->query->get('sort', 'title'),
            'order' => $request->query->get('order', 'ASC'),
            'page' => $request->query->getInt('page', 1),
            'limit' => $request->query->getInt('limit', 25),
            'radarr_instance_id' => $request->query->get('radarr_instance_id'),
        ];

        $result = $this->movieRepository->findWithFilters($filters);

        $data = array_map($this->serializeForList(...), $result['data']);

        return $this->json([
            'data' => $data,
            'meta' => [
                'total' => $result['total'],
                'page' => $result['page'],
                'limit' => $result['limit'],
                'total_pages' => $result['total_pages'],
            ],
        ]);
    }

    /**
     * GET /api/v1/movies/{id} — Movie detail with linked files.
     */
    #[Route('/{id}', methods: ['GET'])]
    #[IsGranted('ROLE_GUEST')]
    public function detail(string $id): JsonResponse
    {
        $movie = $this->movieRepository->find($id);

        if (!$movie instanceof Movie) {
            return $this->json(
                ['error' => ['code' => 404, 'message' => 'Movie not found']],
                Response::HTTP_NOT_FOUND,
            );
        }

        return $this->json(['data' => $this->serializeDetail($movie)]);
    }

    /**
     * DELETE /api/v1/movies/{id} — Global movie deletion (à la carte).
     *
     * Creates an ephemeral ScheduledDeletion and executes it through the standard pipeline.
     * Physical file deletion is handled by the watcher via WebSocket.
     */
    #[Route('/{id}', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADVANCED_USER')]
    public function delete(string $id, Request $request): JsonResponse
    {
        $movie = $this->movieRepository->find($id);

        if (!$movie instanceof Movie) {
            return $this->json(
                ['error' => ['code' => 404, 'message' => 'Movie not found']],
                Response::HTTP_NOT_FOUND,
            );
        }

        $payload = json_decode($request->getContent(), true) ?? [];

        $fileIds = $payload['file_ids'] ?? [];
        $deleteRadarrReference = (bool)($payload['delete_radarr_reference'] ?? false);
        $deleteMediaPlayerReference = (bool)($payload['delete_media_player_reference'] ?? false);
        $disableRadarrAutoSearch = (bool)($payload['disable_radarr_auto_search'] ?? false);

        // Validate that all file_ids actually belong to this movie (via explicit DB query)
        if (!empty($fileIds)) {
            $movieFiles = $this->movieFileRepository->findBy(['movie' => $movie]);
            $validFileIds = [];
            foreach ($movieFiles as $mf) {
                $mediaFile = $mf->getMediaFile();
                if ($mediaFile !== null) {
                    $validFileIds[] = (string) $mediaFile->getId();
                }
            }
            $invalidIds = array_diff($fileIds, $validFileIds);
            if (!empty($invalidIds)) {
                return $this->json(
                    ['error' => ['code' => 400, 'message' => 'Some file_ids do not belong to this movie', 'invalid_ids' => array_values($invalidIds)]],
                    Response::HTTP_BAD_REQUEST,
                );
            }
        }

        /** @var User $user */
        $user = $this->getUser();

        // Create ephemeral ScheduledDeletion
        $deletion = new ScheduledDeletion();
        $deletion->setCreatedBy($user);
        $deletion->setScheduledDate(new DateTime('today'));
        $deletion->setDeletePhysicalFiles(!empty($fileIds));
        $deletion->setDeleteRadarrReference($deleteRadarrReference);
        $deletion->setDeleteMediaPlayerReference($deleteMediaPlayerReference);
        $deletion->setDisableRadarrAutoSearch($disableRadarrAutoSearch);

        $item = new ScheduledDeletionItem();
        $item->setMovie($movie);
        $item->setMediaFileIds($fileIds);
        $deletion->addItem($item);

        $this->em->persist($deletion);
        $this->em->flush();

        // Execute through the standard pipeline
        $this->deletionService->executeDeletion($deletion);

        // Log activity
        $log = new ActivityLog();
        $log->setAction('movie.deleted');
        $log->setEntityType('movie');
        $log->setEntityId($movie->getId());
        $log->setDetails([
            'title' => $movie->getTitle(),
            'deletion_id' => (string)$deletion->getId(),
            'status' => $deletion->getStatus()->value,
            'radarr_dereferenced' => $deleteRadarrReference,
            'files_count' => count($fileIds),
        ]);
        $log->setUser($user);
        $this->em->persist($log);
        $this->em->flush();

        // Determine HTTP status based on deletion status
        $status = $deletion->getStatus();
        $httpCode = match ($status) {
            DeletionStatus::EXECUTING => Response::HTTP_ACCEPTED,
            DeletionStatus::WAITING_WATCHER => Response::HTTP_ACCEPTED,
            DeletionStatus::COMPLETED => Response::HTTP_OK,
            default => Response::HTTP_OK,
        };

        $response = [
            'data' => [
                'message' => 'Deletion initiated',
                'deletion_id' => (string)$deletion->getId(),
                'status' => $status->value,
                'files_count' => count($fileIds),
                'radarr_dereferenced' => $deleteRadarrReference,
            ],
        ];

        // Warning if Radarr auto-search is still enabled
        if (!$disableRadarrAutoSearch && !$deleteRadarrReference && $movie->isRadarrMonitored() && $movie->getRadarrInstance() !== null) {
            $response['data']['warning'] = 'Radarr auto-search is still enabled for this movie. It may be re-downloaded.';
        }

        return $this->json($response, $httpCode);
    }

    /**
     * POST /api/v1/movies/sync — Trigger Radarr sync + TMDB enrichment + matching.
     */
    #[Route('/sync', methods: ['POST'], priority: 10)]
    #[IsGranted('ROLE_ADMIN')]
    public function sync(): JsonResponse
    {
        // Run the command in a fully detached background process
        $consolePath = $this->projectDir . '/bin/console';
        $logPath = $this->projectDir . '/var/log/sync-radarr.log';
        $command = sprintf(
            'nohup php %s scanarr:sync-radarr --no-interaction >> %s 2>&1 &',
            escapeshellarg($consolePath),
            escapeshellarg($logPath),
        );

        exec($command);

        return $this->json([
            'data' => ['message' => 'Radarr sync started. Movies will be imported in the background.'],
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * Serialize a movie for the list endpoint (with file summary).
     *
     * @return array<string, mixed>
     */
    private function serializeForList(Movie $movie): array
    {
        $movieFiles = $movie->getMovieFiles();
        $filesSummary = [];
        $maxFileSize = 0;

        foreach ($movieFiles as $mf) {
            $mediaFile = $mf->getMediaFile();
            if ($mediaFile === null) {
                continue;
            }

            $size = $mediaFile->getFileSizeBytes();
            if ($size > $maxFileSize) {
                $maxFileSize = $size;
            }

            $filesSummary[] = [
                'id' => (string)$mediaFile->getId(),
                'file_name' => $mediaFile->getFileName(),
                'file_size_bytes' => $size,
                'resolution' => $mediaFile->getResolution(),
                'volume_name' => $mediaFile->getVolume()?->getName(),
            ];
        }

        return [
            'id' => (string)$movie->getId(),
            'tmdb_id' => $movie->getTmdbId(),
            'title' => $movie->getTitle(),
            'original_title' => $movie->getOriginalTitle(),
            'year' => $movie->getYear(),
            'synopsis' => $movie->getSynopsis(),
            'poster_url' => $movie->getPosterUrl(),
            'genres' => $movie->getGenres(),
            'rating' => $movie->getRating() !== null ? (float)$movie->getRating() : null,
            'runtime_minutes' => $movie->getRuntimeMinutes(),
            'file_count' => count($filesSummary),
            'max_file_size_bytes' => $maxFileSize,
            'files_summary' => $filesSummary,
            'is_monitored_radarr' => $movie->isRadarrMonitored() ?? false,
        ];
    }

    /**
     * Serialize a movie for the detail endpoint (with full file info).
     *
     * @return array<string, mixed>
     */
    private function serializeDetail(Movie $movie): array
    {
        $files = [];

        foreach ($movie->getMovieFiles() as $mf) {
            $mediaFile = $mf->getMediaFile();
            if ($mediaFile === null) {
                continue;
            }

            $files[] = [
                'id' => (string)$mediaFile->getId(),
                'volume_id' => (string)$mediaFile->getVolume()?->getId(),
                'volume_name' => $mediaFile->getVolume()?->getName(),
                'file_path' => $mediaFile->getFilePath(),
                'file_name' => $mediaFile->getFileName(),
                'file_size_bytes' => $mediaFile->getFileSizeBytes(),
                'hardlink_count' => $mediaFile->getHardlinkCount(),
                'resolution' => $mediaFile->getResolution(),
                'codec' => $mediaFile->getCodec(),
                'quality' => $mediaFile->getQuality(),
                'is_linked_radarr' => $mediaFile->isLinkedRadarr(),
                'is_linked_media_player' => $mediaFile->isLinkedMediaPlayer(),
                'matched_by' => $mf->getMatchedBy(),
                'confidence' => $mf->getConfidence() !== null ? (float)$mf->getConfidence() : null,
            ];
        }

        $radarrInstance = $movie->getRadarrInstance();

        return [
            'id' => (string)$movie->getId(),
            'tmdb_id' => $movie->getTmdbId(),
            'title' => $movie->getTitle(),
            'original_title' => $movie->getOriginalTitle(),
            'year' => $movie->getYear(),
            'synopsis' => $movie->getSynopsis(),
            'poster_url' => $movie->getPosterUrl(),
            'backdrop_url' => $movie->getBackdropUrl(),
            'genres' => $movie->getGenres(),
            'rating' => $movie->getRating() !== null ? (float)$movie->getRating() : null,
            'runtime_minutes' => $movie->getRuntimeMinutes(),
            'radarr_instance' => $radarrInstance instanceof RadarrInstance ? [
                'id' => (string)$radarrInstance->getId(),
                'name' => $radarrInstance->getName(),
            ] : null,
            'radarr_monitored' => $movie->isRadarrMonitored() ?? false,
            'files' => $files,
        ];
    }
}
