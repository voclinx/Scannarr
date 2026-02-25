<?php

namespace App\Service;

use App\Entity\MediaFile;
use App\Entity\Movie;
use App\Entity\MovieFile;
use App\Entity\RadarrInstance;
use App\Entity\Volume;
use App\Repository\MediaFileRepository;
use App\Repository\MovieFileRepository;
use App\Repository\MovieRepository;
use App\Repository\RadarrInstanceRepository;
use App\Repository\VolumeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class MovieMatcherService
{
    public function __construct(
        private RadarrInstanceRepository $radarrInstanceRepository,
        private MovieRepository $movieRepository,
        private MovieFileRepository $movieFileRepository,
        private MediaFileRepository $mediaFileRepository,
        private VolumeRepository $volumeRepository,
        private EntityManagerInterface $em,
        private RadarrService $radarrService,
        private TmdbService $tmdbService,
        private FileNameParser $fileNameParser,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Run full matching: Radarr API first, then filename parsing fallback.
     *
     * @return array{radarr_matched: int, filename_matched: int, total_links: int}
     */
    public function matchAll(): array
    {
        $radarrMatched = $this->matchViaRadarr();
        $filenameMatched = $this->matchViaFilenameParsing();

        $totalLinks = $this->movieFileRepository->count([]);

        return [
            'radarr_matched' => $radarrMatched,
            'filename_matched' => $filenameMatched,
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
            } catch (\Exception $e) {
                $this->logger->error('Radarr matching failed for instance', [
                    'instance' => $instance->getName(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $matched;
    }

    /**
     * Step 2 — Match via filename parsing (fallback, confidence 0.5-0.9).
     *
     * For unlinked media files, parse filename to extract title + year,
     * then search in movies table. If no match, optionally call TMDB.
     */
    public function matchViaFilenameParsing(): int
    {
        $matched = 0;

        // Get all media files that are not yet linked to any movie
        $unlinkedFiles = $this->getUnlinkedMediaFiles();

        $this->logger->info('Starting filename matching', [
            'unlinked_files' => count($unlinkedFiles),
        ]);

        $batchCount = 0;
        foreach ($unlinkedFiles as $mediaFile) {
            $parsed = $this->fileNameParser->parse($mediaFile->getFileName());

            if ($parsed['title'] === null) {
                continue;
            }

            // Also update the media file's resolution/quality/codec if parsed
            $this->updateMediaFileMetadata($mediaFile, $parsed);

            // Try to find matching movie in DB
            $movie = $this->findMovieByTitleAndYear($parsed['title'], $parsed['year']);

            if ($movie === null && $parsed['year'] !== null) {
                // Try TMDB search as fallback
                $movie = $this->findMovieViaTmdbSearch($parsed['title'], $parsed['year']);
            }

            if ($movie !== null) {
                $confidence = $this->calculateConfidence($parsed);
                $created = $this->createMovieFileLink($movie, $mediaFile, 'filename_parse', $confidence);

                if ($created) {
                    $matched++;
                }
            }

            $batchCount++;
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
        // 1. Try Radarr API match first
        $movieFile = $this->matchSingleFileViaRadarr($mediaFile);
        if ($movieFile !== null) {
            return $movieFile;
        }

        // 2. Try filename parsing
        $parsed = $this->fileNameParser->parse($mediaFile->getFileName());

        if ($parsed['title'] === null) {
            return null;
        }

        $this->updateMediaFileMetadata($mediaFile, $parsed);

        $movie = $this->findMovieByTitleAndYear($parsed['title'], $parsed['year']);

        if ($movie === null && $parsed['year'] !== null) {
            $movie = $this->findMovieViaTmdbSearch($parsed['title'], $parsed['year']);
        }

        if ($movie !== null) {
            $confidence = $this->calculateConfidence($parsed);
            $this->createMovieFileLink($movie, $mediaFile, 'filename_parse', $confidence);

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
        $matched = 0;

        // Build the root folder mapping: radarr_path => volume + local_path_prefix
        $rootFolderMap = $this->buildRootFolderMap($instance);

        if (empty($rootFolderMap)) {
            $this->logger->warning('No root folder mapping for instance', [
                'instance' => $instance->getName(),
            ]);
            return 0;
        }

        // Get all movies from this instance in our DB
        $movies = $this->movieRepository->findBy(['radarrInstance' => $instance]);

        foreach ($movies as $movie) {
            if ($movie->getRadarrId() === null) {
                continue;
            }

            try {
                $radarrFiles = $this->radarrService->getMovieFiles($instance, $movie->getRadarrId());

                foreach ($radarrFiles as $radarrFile) {
                    $relativePath = $radarrFile['relativePath'] ?? null;
                    $radarrPath = $radarrFile['path'] ?? null;

                    if ($radarrPath === null) {
                        continue;
                    }

                    // Try to match with a media file in our DB
                    $mediaFile = $this->findMediaFileByRadarrPath($radarrPath, $rootFolderMap);

                    if ($mediaFile !== null) {
                        $created = $this->createMovieFileLink($movie, $mediaFile, 'radarr_api', '1.00');

                        if ($created) {
                            // Mark media file as linked to Radarr
                            $mediaFile->setIsLinkedRadarr(true);
                            $mediaFile->setRadarrInstance($instance);
                            $matched++;
                        }
                    }
                }
            } catch (\Exception $e) {
                $this->logger->debug('Failed to get files for movie from Radarr', [
                    'movie' => $movie->getTitle(),
                    'radarr_id' => $movie->getRadarrId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->em->flush();

        return $matched;
    }

    /**
     * Try to match a single file via Radarr API.
     */
    private function matchSingleFileViaRadarr(MediaFile $mediaFile): ?MovieFile
    {
        $instances = $this->radarrInstanceRepository->findBy(['isActive' => true]);

        foreach ($instances as $instance) {
            $rootFolderMap = $this->buildRootFolderMap($instance);

            if (empty($rootFolderMap)) {
                continue;
            }

            // Check if this file's path matches any Radarr root folder mapping
            $volume = $mediaFile->getVolume();
            if ($volume === null) {
                continue;
            }

            foreach ($rootFolderMap as $mapping) {
                if ($mapping['volume']->getId()->equals($volume->getId())) {
                    // This volume is mapped to a Radarr root folder
                    // The file could belong to a movie in this instance
                    $movies = $this->movieRepository->findBy(['radarrInstance' => $instance]);

                    foreach ($movies as $movie) {
                        if ($movie->getRadarrId() === null) {
                            continue;
                        }

                        try {
                            $radarrFiles = $this->radarrService->getMovieFiles($instance, $movie->getRadarrId());

                            foreach ($radarrFiles as $radarrFile) {
                                $matchedFile = $this->findMediaFileByRadarrPath(
                                    $radarrFile['path'] ?? '',
                                    $rootFolderMap
                                );

                                if ($matchedFile !== null && $matchedFile->getId()->equals($mediaFile->getId())) {
                                    $this->createMovieFileLink($movie, $mediaFile, 'radarr_api', '1.00');
                                    $mediaFile->setIsLinkedRadarr(true);
                                    $mediaFile->setRadarrInstance($instance);
                                    $this->em->flush();

                                    return $this->movieFileRepository->findOneBy([
                                        'movie' => $movie,
                                        'mediaFile' => $mediaFile,
                                    ]);
                                }
                            }
                        } catch (\Exception $e) {
                            // Continue trying next movie
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Build a mapping from Radarr root folder paths to volumes.
     *
     * Each Radarr root folder has:
     * - path: the path in Radarr (e.g., /movies)
     * - mapped_path: the actual host path (e.g., /mnt/nas/movies)
     *
     * We need to map these to our volumes (which also have path + host_path).
     *
     * @return array<int, array{radarr_path: string, volume: Volume, volume_subpath: string}>
     */
    private function buildRootFolderMap(RadarrInstance $instance): array
    {
        $rootFolders = $instance->getRootFolders();
        if (empty($rootFolders)) {
            return [];
        }

        $volumes = $this->volumeRepository->findBy(['status' => 'active']);
        $map = [];

        foreach ($rootFolders as $rf) {
            $radarrPath = rtrim($rf['path'] ?? '', '/');
            $mappedPath = rtrim($rf['mapped_path'] ?? $rf['path'] ?? '', '/');

            foreach ($volumes as $volume) {
                $volumeHostPath = rtrim($volume->getHostPath() ?? '', '/');
                $volumePath = rtrim($volume->getPath() ?? '', '/');

                // Check if the mapped path starts with or matches the volume host path
                if ($mappedPath === $volumeHostPath || str_starts_with($mappedPath, $volumeHostPath . '/')) {
                    $subpath = '';
                    if ($mappedPath !== $volumeHostPath) {
                        $subpath = substr($mappedPath, strlen($volumeHostPath) + 1);
                    }

                    $map[] = [
                        'radarr_path' => $radarrPath,
                        'volume' => $volume,
                        'volume_subpath' => $subpath,
                    ];
                    break;
                }

                // Also check against the Docker path
                if ($mappedPath === $volumePath || str_starts_with($mappedPath, $volumePath . '/')) {
                    $subpath = '';
                    if ($mappedPath !== $volumePath) {
                        $subpath = substr($mappedPath, strlen($volumePath) + 1);
                    }

                    $map[] = [
                        'radarr_path' => $radarrPath,
                        'volume' => $volume,
                        'volume_subpath' => $subpath,
                    ];
                    break;
                }
            }
        }

        return $map;
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
                    $relativePath
                );

                if ($mediaFile !== null) {
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
     * Try to find a movie by searching TMDB and matching with our existing movies.
     */
    private function findMovieViaTmdbSearch(string $title, ?int $year): ?Movie
    {
        try {
            $results = $this->tmdbService->searchMovie($title, $year);

            if (empty($results)) {
                return null;
            }

            // Check if the first TMDB result matches a movie in our DB
            $tmdbId = $results[0]['id'] ?? null;
            if ($tmdbId !== null) {
                return $this->movieRepository->findOneBy(['tmdbId' => $tmdbId]);
            }
        } catch (\Exception $e) {
            $this->logger->debug('TMDB search failed during matching', [
                'title' => $title,
                'year' => $year,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Calculate matching confidence based on parsed data completeness.
     */
    private function calculateConfidence(array $parsed): string
    {
        $confidence = 0.5;

        if ($parsed['year'] !== null) {
            $confidence += 0.2;
        }

        if ($parsed['resolution'] !== null) {
            $confidence += 0.1;
        }

        if ($parsed['quality'] !== null) {
            $confidence += 0.05;
        }

        if ($parsed['codec'] !== null) {
            $confidence += 0.05;
        }

        return (string) min(0.9, $confidence);
    }

    /**
     * Create a movie-file link if it doesn't already exist.
     */
    private function createMovieFileLink(Movie $movie, MediaFile $mediaFile, string $matchedBy, string $confidence): bool
    {
        // Check if link already exists
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
     * Update media file metadata from parsed filename.
     *
     * @param array{title: ?string, year: ?int, resolution: ?string, quality: ?string, codec: ?string} $parsed
     */
    private function updateMediaFileMetadata(MediaFile $mediaFile, array $parsed): void
    {
        if ($mediaFile->getResolution() === null && $parsed['resolution'] !== null) {
            $mediaFile->setResolution($parsed['resolution']);
        }

        if ($mediaFile->getQuality() === null && $parsed['quality'] !== null) {
            $mediaFile->setQuality($parsed['quality']);
        }

        if ($mediaFile->getCodec() === null && $parsed['codec'] !== null) {
            $mediaFile->setCodec($parsed['codec']);
        }
    }
}
