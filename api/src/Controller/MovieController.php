<?php

namespace App\Controller;

use App\Entity\ActivityLog;
use App\Entity\Movie;
use App\Entity\RadarrInstance;
use App\Entity\User;
use App\Repository\MediaFileRepository;
use App\Repository\MovieFileRepository;
use App\Repository\MovieRepository;
use App\Service\RadarrService;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Process;
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

        $filesDeleted = 0;
        $radarrDereferenced = false;
        $radarrAutoSearchDisabled = false;
        $warning = null;

        // 1. Delete selected physical files
        if (!empty($fileIds)) {
            foreach ($fileIds as $fileId) {
                $mediaFile = $this->mediaFileRepository->find($fileId);

                if ($mediaFile === null) {
                    continue;
                }

                // Get the physical path for deletion
                $volume = $mediaFile->getVolume();
                if ($volume !== null) {
                    $physicalPath = rtrim($volume->getPath() ?? '', '/') . '/' . $mediaFile->getFilePath();

                    if (file_exists($physicalPath)) {
                        if (@unlink($physicalPath)) {
                            ++$filesDeleted;
                        } else {
                            $this->logger->warning('Failed to delete physical file', [
                                'path' => $physicalPath,
                            ]);
                        }
                    } else {
                        // File already gone, still count it
                        ++$filesDeleted;
                    }
                }

                // Remove from database (cascades to movie_files)
                $this->em->remove($mediaFile);
            }
        }

        // 2. Dereference from Radarr
        if ($deleteRadarrReference && $movie->getRadarrId() !== null && $movie->getRadarrInstance() !== null) {
            try {
                $this->radarrService->deleteMovie(
                    $movie->getRadarrInstance(),
                    $movie->getRadarrId(),
                    false, // don't delete files via Radarr
                    false,  // don't add exclusion
                );
                $radarrDereferenced = true;
            } catch (Exception $e) {
                $this->logger->error('Failed to dereference from Radarr', [
                    'movie' => $movie->getTitle(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 3. Disable Radarr auto-search
        if ($disableRadarrAutoSearch && $movie->getRadarrId() !== null && $movie->getRadarrInstance() !== null) {
            try {
                $radarrMovie = $this->radarrService->getMovie($movie->getRadarrInstance(), $movie->getRadarrId());
                $radarrMovie['monitored'] = false;
                $this->radarrService->updateMovie($movie->getRadarrInstance(), $movie->getRadarrId(), $radarrMovie);
                $radarrAutoSearchDisabled = true;
            } catch (Exception $e) {
                $this->logger->error('Failed to disable Radarr auto-search', [
                    'movie' => $movie->getTitle(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 4. Warning if Radarr auto-search is still enabled
        if (!$disableRadarrAutoSearch && $movie->isRadarrMonitored() && $movie->getRadarrInstance() !== null && !$deleteRadarrReference) {
            $warning = 'Radarr auto-search is still enabled for this movie. It may be re-downloaded.';
        }

        // 5. Log activity
        $user = $this->getUser();
        $log = new ActivityLog();
        $log->setAction('movie.deleted');
        $log->setEntityType('movie');
        $log->setEntityId($movie->getId());
        $log->setDetails([
            'title' => $movie->getTitle(),
            'files_deleted' => $filesDeleted,
            'radarr_dereferenced' => $radarrDereferenced,
        ]);

        if ($user instanceof User) {
            $log->setUser($user);
        }

        $this->em->persist($log);
        $this->em->flush();

        $response = [
            'data' => [
                'message' => 'Movie deletion completed',
                'files_deleted' => $filesDeleted,
                'radarr_dereferenced' => $radarrDereferenced,
                'radarr_auto_search_disabled' => $radarrAutoSearchDisabled,
                'media_player_reference_kept' => !$deleteMediaPlayerReference,
            ],
        ];

        if ($warning !== null) {
            $response['data']['warning'] = $warning;
        }

        return $this->json($response);
    }

    /**
     * POST /api/v1/movies/sync — Trigger Radarr sync + TMDB enrichment + matching.
     */
    #[Route('/sync', methods: ['POST'], priority: 10)]
    #[IsGranted('ROLE_ADMIN')]
    public function sync(): JsonResponse
    {
        // Run the command asynchronously
        $process = new Process(['php', $this->projectDir . '/bin/console', 'scanarr:sync-radarr', '--no-interaction']);
        $process->setWorkingDirectory($this->projectDir);
        $process->setTimeout(null);
        $process->start();

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
