<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\Internal\RadarrParseResult;
use App\Entity\MediaFile;
use App\Entity\Movie;
use App\Entity\MovieFile;
use App\Entity\RadarrInstance;
use App\Entity\Volume;
use App\Enum\VolumeStatus;
use App\ExternalService\MediaManager\RadarrService;
use App\ExternalService\Metadata\TmdbService;
use App\Repository\MediaFileRepository;
use App\Repository\MovieFileRepository;
use App\Repository\MovieRepository;
use App\Repository\RadarrInstanceRepository;
use App\Repository\VolumeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;

/**
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 */
final readonly class MovieMatcherService
{
    /**
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        private RadarrInstanceRepository $radarrInstanceRepository,
        private MovieRepository $movieRepository,
        private MovieFileRepository $movieFileRepository,
        private MediaFileRepository $mediaFileRepository,
        private VolumeRepository $volumeRepository,
        private EntityManagerInterface $em,
        private RadarrService $radarrService,
        private TmdbService $tmdbService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Run full matching: Radarr API first, then Radarr parse + TMDB fallback.
     *
     * @return array{radarr_matched: int, parse_matched: int, total_links: int}
     */
    public function matchAll(): array
    {
        $radarrMatched = $this->matchViaRadarr();
        $parseMatched = $this->matchViaRadarrParsing();

        $totalLinks = $this->movieFileRepository->count([]);

        return [
            'radarr_matched' => $radarrMatched,
            'parse_matched' => $parseMatched,
            'total_links' => $totalLinks,
        ];
    }

    /**
     * Step 1 — Match via Radarr API (priority, confidence 1.0).
     *
     * For each active Radarr instance, gets movies with their files.
     * Matches Radarr files with media_files in DB via path (root folder mapping).
     */
    public function matchViaRadarr(): int
    {
        $instances = $this->radarrInstanceRepository->findBy(['isActive' => true]);
        $matched = 0;

        foreach ($instances as $instance) {
            try {
                $matched += $this->matchInstanceViaRadarr($instance);
            } catch (Exception $e) {
                $this->logger->error('Radarr matching failed for instance', [
                    'instance' => $instance->getName(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $matched;
    }

    /**
     * Step 2 — Match via Radarr parse API + TMDB (fallback, confidence 0.7-0.9).
     *
     * For unlinked media files, use Radarr's parse endpoint to extract title + year,
     * then search in movies table. If no match in DB, search TMDB and create the movie.
     */
    public function matchViaRadarrParsing(): int
    {
        $matched = 0;

        $unlinkedFiles = $this->getUnlinkedMediaFiles();

        $this->logger->info('Starting Radarr parse matching', [
            'unlinked_files' => count($unlinkedFiles),
        ]);

        $batchCount = 0;
        foreach ($unlinkedFiles as $mediaFile) {
            $movie = $this->matchFileViaRadarrParse($mediaFile);

            if ($movie instanceof Movie) {
                $created = $this->createMovieFileLink($movie, $mediaFile, 'radarr_parse', '0.80');

                if ($created) {
                    ++$matched;
                }
            }

            ++$batchCount;
            if ($batchCount % 50 === 0) {
                $this->em->flush();
            }
        }

        $this->em->flush();

        return $matched;
    }

    /**
     * Try to match a single media file (called when a new file is detected by watcher).
     */
    public function matchSingleFile(MediaFile $mediaFile): ?MovieFile
    {
        // 1. Try Radarr API path match first (confidence 1.0)
        $movieFile = $this->matchSingleFileViaRadarr($mediaFile);
        if ($movieFile instanceof MovieFile) {
            return $movieFile;
        }

        // 2. Try Radarr parse + TMDB (confidence 0.7-0.9)
        $movie = $this->matchFileViaRadarrParse($mediaFile);

        if ($movie instanceof Movie) {
            $this->createMovieFileLink($movie, $mediaFile, 'radarr_parse', '0.80');
            $this->em->flush();

            return $this->movieFileRepository->findOneBy([
                'movie' => $movie,
                'mediaFile' => $mediaFile,
            ]);
        }

        return null;
    }

    /**
     * Match files for a specific Radarr instance.
     */
    private function matchInstanceViaRadarr(RadarrInstance $instance): int
    {
        $rootFolderMap = $this->buildRootFolderMap($instance);

        if ($rootFolderMap === []) {
            $this->logger->warning('No root folder mapping for instance', [
                'instance' => $instance->getName(),
            ]);

            return 0;
        }

        $movies = $this->movieRepository->findBy(['radarrInstance' => $instance]);
        $matched = 0;

        foreach ($movies as $movie) {
            $matched += $this->matchMovieFilesViaRadarr($instance, $movie, $rootFolderMap);
        }

        $this->em->flush();

        return $matched;
    }

    /**
     * Match files for a single movie via Radarr API.
     *
     * @param array<int, array{radarr_path: string, volume: Volume, volume_subpath: string}> $rootFolderMap
     */
    private function matchMovieFilesViaRadarr(RadarrInstance $instance, Movie $movie, array $rootFolderMap): int
    {
        if ($movie->getRadarrId() === null) {
            return 0;
        }

        try {
            $radarrFiles = $this->radarrService->getMovieFiles($instance, $movie->getRadarrId());
        } catch (Exception $e) {
            $this->logger->debug('Failed to get files for movie from Radarr', [
                'movie' => $movie->getTitle(),
                'radarr_id' => $movie->getRadarrId(),
                'error' => $e->getMessage(),
            ]);

            return 0;
        }

        $matched = 0;
        foreach ($radarrFiles as $radarrFile) {
            $matched += $this->matchSingleRadarrFile($instance, $movie, $radarrFile, $rootFolderMap);
        }

        return $matched;
    }

    /**
     * @param array<string, mixed> $radarrFile
     * @param array<int, array{radarr_path: string, volume: Volume, volume_subpath: string}> $rootFolderMap
     */
    private function matchSingleRadarrFile(RadarrInstance $instance, Movie $movie, array $radarrFile, array $rootFolderMap): int
    {
        $radarrPath = $radarrFile['path'] ?? null;
        if ($radarrPath === null) {
            return 0;
        }

        $mediaFile = $this->findMediaFileByRadarrPath($radarrPath, $rootFolderMap);
        if (!$mediaFile instanceof MediaFile) {
            return 0;
        }

        $created = $this->createMovieFileLink($movie, $mediaFile, 'radarr_api', '1.00');
        if (!$created) {
            return 0;
        }

        $mediaFile->setIsLinkedRadarr(true);
        $mediaFile->setRadarrInstance($instance);

        return 1;
    }

    /**
     * Try to match a single file via Radarr API.
     */
    private function matchSingleFileViaRadarr(MediaFile $mediaFile): ?MovieFile
    {
        $volume = $mediaFile->getVolume();
        if (!$volume instanceof Volume) {
            return null;
        }

        $instances = $this->radarrInstanceRepository->findBy(['isActive' => true]);

        foreach ($instances as $instance) {
            $result = $this->tryMatchFileInInstance($mediaFile, $volume, $instance);
            if ($result instanceof MovieFile) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Try to match a media file within a specific Radarr instance.
     */
    private function tryMatchFileInInstance(MediaFile $mediaFile, Volume $volume, RadarrInstance $instance): ?MovieFile
    {
        $rootFolderMap = $this->buildRootFolderMap($instance);
        if ($rootFolderMap === []) {
            return null;
        }

        if (!$this->isVolumeMappedToInstance($volume, $rootFolderMap)) {
            return null;
        }

        $movies = $this->movieRepository->findBy(['radarrInstance' => $instance]);

        foreach ($movies as $movie) {
            $result = $this->tryMatchFileAgainstMovie($mediaFile, $movie, $instance, $rootFolderMap);
            if ($result instanceof MovieFile) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @param array<int, array{radarr_path: string, volume: Volume, volume_subpath: string}> $rootFolderMap
     */
    private function isVolumeMappedToInstance(Volume $volume, array $rootFolderMap): bool
    {
        foreach ($rootFolderMap as $mapping) {
            if ($mapping['volume']->getId()->equals($volume->getId())) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array{radarr_path: string, volume: Volume, volume_subpath: string}> $rootFolderMap
     */
    private function tryMatchFileAgainstMovie(
        MediaFile $mediaFile,
        Movie $movie,
        RadarrInstance $instance,
        array $rootFolderMap,
    ): ?MovieFile {
        if ($movie->getRadarrId() === null) {
            return null;
        }

        try {
            $radarrFiles = $this->radarrService->getMovieFiles($instance, $movie->getRadarrId());
        } catch (Exception) {
            return null;
        }

        return $this->findAndLinkMatch($mediaFile, $movie, $instance, $radarrFiles, $rootFolderMap);
    }

    /**
     * @param array<int, array<string, mixed>> $radarrFiles
     * @param array<int, array{radarr_path: string, volume: Volume, volume_subpath: string}> $rootFolderMap
     */
    private function findAndLinkMatch(
        MediaFile $mediaFile,
        Movie $movie,
        RadarrInstance $instance,
        array $radarrFiles,
        array $rootFolderMap,
    ): ?MovieFile {
        foreach ($radarrFiles as $radarrFile) {
            $matchedFile = $this->findMediaFileByRadarrPath($radarrFile['path'] ?? '', $rootFolderMap);
            if (!$matchedFile instanceof MediaFile) {
                continue;
            }
            if (!$matchedFile->getId()->equals($mediaFile->getId())) {
                continue;
            }

            $this->createMovieFileLink($movie, $mediaFile, 'radarr_api', '1.00');
            $mediaFile->setIsLinkedRadarr(true);
            $mediaFile->setRadarrInstance($instance);
            $this->em->flush();

            return $this->movieFileRepository->findOneBy(['movie' => $movie, 'mediaFile' => $mediaFile]);
        }

        return null;
    }

    /**
     * Build a mapping from Radarr root folder paths to volumes.
     *
     * @return array<int, array{radarr_path: string, volume: Volume, volume_subpath: string}>
     */
    private function buildRootFolderMap(RadarrInstance $instance): array
    {
        $rootFolders = $instance->getRootFolders();
        if ($rootFolders === null || $rootFolders === []) {
            return [];
        }

        $volumes = $this->volumeRepository->findBy(['status' => VolumeStatus::ACTIVE]);
        $map = [];

        foreach ($rootFolders as $rf) {
            $radarrPath = rtrim($rf['path'] ?? '', '/');
            $mappedPath = rtrim($rf['mapped_path'] ?? $rf['path'] ?? '', '/');

            $mapping = $this->matchRootFolderToVolume($radarrPath, $mappedPath, $volumes);
            if ($mapping !== null) {
                $map[] = $mapping;
            }
        }

        return $map;
    }

    /**
     * Try to match a single root folder to a volume.
     *
     * @param Volume[] $volumes
     *
     * @return array{radarr_path: string, volume: Volume, volume_subpath: string}|null
     */
    private function matchRootFolderToVolume(string $radarrPath, string $mappedPath, array $volumes): ?array
    {
        foreach ($volumes as $volume) {
            $hostPath = rtrim($volume->getHostPath() ?? '', '/');
            $dockerPath = rtrim($volume->getPath() ?? '', '/');

            $subpath = $this->extractSubpath($mappedPath, $hostPath);
            if ($subpath === null) {
                $subpath = $this->extractSubpath($mappedPath, $dockerPath);
            }

            if ($subpath !== null) {
                return [
                    'radarr_path' => $radarrPath,
                    'volume' => $volume,
                    'volume_subpath' => $subpath,
                ];
            }
        }

        return null;
    }

    /**
     * Extract subpath if mappedPath starts with basePath. Returns null if no match.
     */
    private function extractSubpath(string $mappedPath, string $basePath): ?string
    {
        if ($basePath === '') {
            return null;
        }

        if ($mappedPath === $basePath) {
            return '';
        }

        if (str_starts_with($mappedPath, $basePath . '/')) {
            return substr($mappedPath, strlen($basePath) + 1);
        }

        return null;
    }

    /**
     * Find a MediaFile by matching a Radarr file path with our volumes.
     *
     * @param array<int, array{radarr_path: string, volume: Volume, volume_subpath: string}> $rootFolderMap
     */
    private function findMediaFileByRadarrPath(string $radarrFilePath, array $rootFolderMap): ?MediaFile
    {
        foreach ($rootFolderMap as $mapping) {
            $radarrRoot = $mapping['radarr_path'];

            if (str_starts_with($radarrFilePath, $radarrRoot . '/')) {
                // Extract the relative path after the Radarr root folder
                $relativePath = substr($radarrFilePath, strlen($radarrRoot) + 1);

                // Prepend volume subpath if any
                if (!empty($mapping['volume_subpath'])) {
                    $relativePath = $mapping['volume_subpath'] . '/' . $relativePath;
                }

                // Find in our DB
                $mediaFile = $this->mediaFileRepository->findByVolumeAndFilePath(
                    $mapping['volume'],
                    $relativePath,
                );

                if ($mediaFile instanceof MediaFile) {
                    return $mediaFile;
                }
            }
        }

        return null;
    }

    /**
     * Get all media files not linked to any movie.
     *
     * @return MediaFile[]
     */
    private function getUnlinkedMediaFiles(): array
    {
        $qb = $this->em->createQueryBuilder();

        return $qb->select('mf')
            ->from(MediaFile::class, 'mf')
            ->leftJoin('mf.movieFiles', 'mvf')
            ->where('mvf.id IS NULL')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find a movie by title (fuzzy) and optional year.
     */
    private function findMovieByTitleAndYear(string $title, ?int $year): ?Movie
    {
        $qb = $this->movieRepository->createQueryBuilder('m');

        $qb->where('LOWER(m.title) LIKE LOWER(:title)')
            ->setParameter('title', '%' . $title . '%');

        if ($year !== null) {
            $qb->andWhere('m.year = :year')
                ->setParameter('year', $year);
        }

        $results = $qb->setMaxResults(1)->getQuery()->getResult();

        return $results[0] ?? null;
    }

    /**
     * Try to match a single file via Radarr parse API + TMDB.
     */
    private function matchFileViaRadarrParse(MediaFile $mediaFile): ?Movie
    {
        $parseResult = $this->parseViaRadarr($mediaFile->getFileName());
        if (!$parseResult instanceof RadarrParseResult) {
            return null;
        }

        $this->updateMediaFileMetadataFromParse($mediaFile, $parseResult);

        if ($parseResult->hasMovie()) {
            $movie = $this->findOrCreateMovieByTmdbId($parseResult->tmdbId);
            if ($movie instanceof Movie) {
                return $movie;
            }
        }

        return $this->matchByParsedTitles($parseResult);
    }

    /**
     * Try each parsed title: search DB first, then TMDB.
     */
    private function matchByParsedTitles(RadarrParseResult $parseResult): ?Movie
    {
        foreach ($parseResult->titles as $title) {
            $movie = $this->findMovieByTitleAndYear($title, $parseResult->year);
            if ($movie instanceof Movie) {
                return $movie;
            }

            if ($parseResult->year === null) {
                continue;
            }

            $movie = $this->findOrCreateMovieViaTmdb($title, $parseResult->year);
            if ($movie instanceof Movie) {
                return $movie;
            }
        }

        return null;
    }

    /**
     * Parse a filename using the Radarr parse API.
     *
     * Tries the first active Radarr instance. Best-effort: returns null on failure.
     */
    private function parseViaRadarr(string $fileName): ?RadarrParseResult
    {
        $instances = $this->radarrInstanceRepository->findBy(['isActive' => true]);

        foreach ($instances as $instance) {
            try {
                $data = $this->radarrService->parseTitle($instance, $fileName);
                $result = RadarrParseResult::fromRadarrResponse($data);

                if ($result->titles !== []) {
                    return $result;
                }
            } catch (Exception $e) {
                $this->logger->debug('Radarr parse failed', [
                    'instance' => $instance->getName(),
                    'file_name' => $fileName,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    /**
     * Find or create a movie by TMDB ID.
     */
    private function findOrCreateMovieByTmdbId(int $tmdbId): ?Movie
    {
        $movie = $this->movieRepository->findOneBy(['tmdbId' => $tmdbId]);

        return $movie ?? $this->createMovieFromTmdb($tmdbId);
    }

    /**
     * Search TMDB by title + year. If found and not in DB, create the movie.
     */
    private function findOrCreateMovieViaTmdb(string $title, ?int $year): ?Movie
    {
        try {
            $results = $this->tmdbService->searchMovie($title, $year);

            if ($results === []) {
                return null;
            }

            $tmdbId = $results[0]['id'] ?? null;
            if ($tmdbId === null) {
                return null;
            }

            return $this->findOrCreateMovieByTmdbId((int)$tmdbId);
        } catch (Exception $e) {
            $this->logger->debug('TMDB search failed during matching', [
                'title' => $title,
                'year' => $year,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Create a Movie entity from TMDB data.
     */
    private function createMovieFromTmdb(int $tmdbId): ?Movie
    {
        try {
            $data = $this->tmdbService->enrichMovieData($tmdbId);
            if ($data === []) {
                return null;
            }

            $movie = $this->buildMovieFromTmdbData($tmdbId, $data);
            $this->em->persist($movie);

            $this->logger->info('Created movie from TMDB', [
                'tmdb_id' => $tmdbId,
                'title' => $movie->getTitle(),
            ]);

            return $movie;
        } catch (Exception $e) {
            $this->logger->warning('Failed to create movie from TMDB', [
                'tmdb_id' => $tmdbId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Build a Movie entity from TMDB response data.
     *
     * @param array<string, mixed> $data
     */
    private function buildMovieFromTmdbData(int $tmdbId, array $data): Movie
    {
        $movie = new Movie();
        $movie->setTmdbId($tmdbId);
        $movie->setTitle($data['title'] ?? 'Unknown');
        $movie->setOriginalTitle($data['original_title'] ?? null);
        $movie->setYear($data['year'] ?? null);
        $movie->setSynopsis($data['synopsis'] ?? null);
        $movie->setPosterUrl($data['poster_url'] ?? null);
        $movie->setBackdropUrl($data['backdrop_url'] ?? null);
        $movie->setGenres($data['genres'] ?? null);
        $movie->setRating(isset($data['rating']) ? (string)$data['rating'] : null);
        $movie->setRuntimeMinutes($data['runtime_minutes'] ?? null);
        $movie->setRadarrMonitored(false);
        $movie->setRadarrHasFile(false);

        return $movie;
    }

    /**
     * Create a movie-file link if it doesn't already exist.
     */
    private function createMovieFileLink(Movie $movie, MediaFile $mediaFile, string $matchedBy, string $confidence): bool
    {
        $existing = $this->movieFileRepository->findOneBy([
            'movie' => $movie,
            'mediaFile' => $mediaFile,
        ]);

        if ($existing !== null) {
            return false;
        }

        $movieFile = new MovieFile();
        $movieFile->setMovie($movie);
        $movieFile->setMediaFile($mediaFile);
        $movieFile->setMatchedBy($matchedBy);
        $movieFile->setConfidence($confidence);

        $this->em->persist($movieFile);

        return true;
    }

    /**
     * Update media file metadata from Radarr parse result.
     */
    private function updateMediaFileMetadataFromParse(MediaFile $mediaFile, RadarrParseResult $parseResult): void
    {
        if ($mediaFile->getResolution() === null && $parseResult->resolution !== null) {
            $mediaFile->setResolution($parseResult->resolution);
        }

        if ($mediaFile->getQuality() === null && $parseResult->quality !== null) {
            $mediaFile->setQuality($parseResult->quality);
        }

        if ($mediaFile->getCodec() === null && $parseResult->codec !== null) {
            $mediaFile->setCodec($parseResult->codec);
        }
    }
}
