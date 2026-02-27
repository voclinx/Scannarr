<?php

namespace App\Controller;

use App\Entity\ActivityLog;
use App\Entity\Movie;
use App\Entity\RadarrInstance;
use App\Entity\ScheduledDeletion;
use App\Entity\ScheduledDeletionItem;
use App\Entity\TorrentStat;
use App\Entity\User;
use App\Enum\DeletionStatus;
use App\Enum\TorrentStatus;
use App\Repository\MediaFileRepository;
use App\Repository\MovieFileRepository;
use App\Repository\MovieRepository;
use App\Repository\TorrentStatRepository;
use App\Service\DeletionService;
use App\Service\HardlinkReplacementService;
use App\Service\RadarrService;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
        private readonly TorrentStatRepository $torrentStatRepository,
        private readonly EntityManagerInterface $em,
        private readonly RadarrService $radarrService,
        private readonly DeletionService $deletionService,
        private readonly HardlinkReplacementService $hardlinkReplacementService,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')]
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
        $replacementMap = $payload['replacement_map'] ?? [];

        // Validate that all file_ids actually belong to this movie (via explicit DB query)
        if (!empty($fileIds)) {
            $movieFiles = $this->movieFileRepository->findBy(['movie' => $movie]);
            $validFileIds = [];
            foreach ($movieFiles as $mf) {
                $mediaFile = $mf->getMediaFile();
                if ($mediaFile !== null) {
                    $validFileIds[] = (string)$mediaFile->getId();
                }
            }
            $invalidIds = array_diff($fileIds, $validFileIds);
            if ($invalidIds !== []) {
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

        // Handle replacement_map (hardlink flow)
        if (!empty($replacementMap)) {
            $movieFiles = $this->movieFileRepository->findBy(['movie' => $movie]);
            $fileById = [];
            foreach ($movieFiles as $mf) {
                $mf2 = $mf->getMediaFile();
                if ($mf2 !== null) {
                    $fileById[(string)$mf2->getId()] = $mf2;
                }
            }

            foreach ($replacementMap as $oldFileId => $newFileId) {
                $oldFile = $fileById[$oldFileId] ?? null;
                $newFile = $fileById[$newFileId] ?? null;

                if ($oldFile === null || $newFile === null) {
                    return $this->json(
                        ['error' => ['code' => 400, 'message' => 'Invalid replacement_map: file IDs not found on this movie']],
                        Response::HTTP_BAD_REQUEST,
                    );
                }
                if (!$oldFile->isLinkedMediaPlayer()) {
                    return $this->json(
                        ['error' => ['code' => 400, 'message' => "File $oldFileId is not a media player file"]],
                        Response::HTTP_BAD_REQUEST,
                    );
                }

                $sent = $this->hardlinkReplacementService->requestReplacement(
                    (string)$deletion->getId(),
                    $oldFile,
                    $newFile,
                );

                if (!$sent) {
                    $deletion->setStatus(DeletionStatus::WAITING_WATCHER);
                    $this->em->flush();

                    return $this->buildDeleteResponse($deletion, $movie, $fileIds, $deleteRadarrReference, $disableRadarrAutoSearch, $user);
                }
            }

            // Hardlink command(s) sent — deletion continues async after files.hardlink.completed
            $this->em->flush();

            return $this->buildDeleteResponse($deletion, $movie, $fileIds, $deleteRadarrReference, $disableRadarrAutoSearch, $user);
        }

        // Standard flow (no replacement_map)
        $this->deletionService->executeDeletion($deletion);

        return $this->buildDeleteResponse($deletion, $movie, $fileIds, $deleteRadarrReference, $disableRadarrAutoSearch, $user);
    }

    /**
     * Build the standardised delete response and log activity.
     */
    private function buildDeleteResponse(
        ScheduledDeletion $deletion,
        Movie $movie,
        array $fileIds,
        bool $deleteRadarrReference,
        bool $disableRadarrAutoSearch,
        User $user,
    ): JsonResponse {
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

        $status = $deletion->getStatus();
        $httpCode = match ($status) {
            DeletionStatus::EXECUTING, DeletionStatus::WAITING_WATCHER => Response::HTTP_ACCEPTED,
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
        if (!$disableRadarrAutoSearch && !$deleteRadarrReference && $movie->isRadarrMonitored() && $movie->getRadarrInstance() instanceof RadarrInstance) {
            $response['data']['warning'] = 'Radarr auto-search is still enabled for this movie. It may be re-downloaded.';
        }

        return $this->json($response, $httpCode);
    }

    /**
     * PUT /api/v1/movies/{id}/protect — Toggle movie protection.
     */
    #[Route('/{id}/protect', methods: ['PUT'], priority: 10)]
    #[IsGranted('ROLE_ADVANCED_USER')]
    public function protect(string $id, Request $request): JsonResponse
    {
        $movie = $this->movieRepository->find($id);

        if (!$movie instanceof Movie) {
            return $this->json(
                ['error' => ['code' => 404, 'message' => 'Movie not found']],
                Response::HTTP_NOT_FOUND,
            );
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        $isProtected = (bool)($payload['is_protected'] ?? false);

        $movie->setIsProtected($isProtected);
        $this->em->flush();

        return $this->json([
            'data' => [
                'id' => (string)$movie->getId(),
                'is_protected' => $movie->isProtected(),
            ],
        ]);
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
        $bestRatio = null;
        $worstRatio = null;
        $maxSeedTime = null;
        $crossSeedCount = 0;
        $fileSeedingStatuses = [];

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

            // Collect torrent stats for this file
            $stats = $this->torrentStatRepository->findByMediaFile($mediaFile);
            $crossSeedCount += count($stats);
            $hasActive = false;

            foreach ($stats as $stat) {
                $ratio = (float)$stat->getRatio();
                if ($bestRatio === null || $ratio > $bestRatio) {
                    $bestRatio = $ratio;
                }
                if ($worstRatio === null || $ratio < $worstRatio) {
                    $worstRatio = $ratio;
                }
                $seedTime = $stat->getSeedTimeSeconds();
                if ($maxSeedTime === null || $seedTime > $maxSeedTime) {
                    $maxSeedTime = $seedTime;
                }
                if (in_array($stat->getStatus(), [TorrentStatus::SEEDING, TorrentStatus::STALLED], true)) {
                    $hasActive = true;
                }
            }

            $fileSeedingStatuses[] = $stats === [] ? 'orphan' : ($hasActive ? 'seeding' : 'inactive');
        }

        // Calculate movie-level seeding status
        $uniqueStatuses = array_unique($fileSeedingStatuses);
        if ($uniqueStatuses === []) {
            $seedingStatus = 'orphan';
        } elseif (count($uniqueStatuses) === 1) {
            $seedingStatus = $uniqueStatuses[0];
        } else {
            $seedingStatus = 'mixed';
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
            'is_protected' => $movie->isProtected(),
            'multi_file_badge' => count($filesSummary) > 1,
            'best_ratio' => $bestRatio,
            'worst_ratio' => $worstRatio,
            'total_seed_time_max_seconds' => $maxSeedTime,
            'seeding_status' => $seedingStatus,
            'cross_seed_count' => $crossSeedCount,
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

            $torrentStats = $this->torrentStatRepository->findByMediaFile($mediaFile);

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
                'is_protected' => $mediaFile->isProtected(),
                'matched_by' => $mf->getMatchedBy(),
                'confidence' => $mf->getConfidence() !== null ? (float)$mf->getConfidence() : null,
                'cross_seed_count' => count($torrentStats),
                'torrents' => array_map($this->serializeTorrentStat(...), $torrentStats),
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
            'is_protected' => $movie->isProtected(),
            'radarr_instance' => $radarrInstance instanceof RadarrInstance ? [
                'id' => (string)$radarrInstance->getId(),
                'name' => $radarrInstance->getName(),
            ] : null,
            'radarr_monitored' => $movie->isRadarrMonitored() ?? false,
            'files' => $files,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTorrentStat(TorrentStat $stat): array
    {
        return [
            'torrent_hash' => $stat->getTorrentHash(),
            'torrent_name' => $stat->getTorrentName(),
            'tracker_domain' => $stat->getTrackerDomain(),
            'ratio' => (float)$stat->getRatio(),
            'seed_time_seconds' => $stat->getSeedTimeSeconds(),
            'uploaded_bytes' => $stat->getUploadedBytes(),
            'downloaded_bytes' => $stat->getDownloadedBytes(),
            'size_bytes' => $stat->getSizeBytes(),
            'status' => $stat->getStatus()->value,
            'added_at' => $stat->getAddedAt()?->format('c'),
            'last_activity_at' => $stat->getLastActivityAt()?->format('c'),
        ];
    }
}
