<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Movie;
use App\Entity\RadarrInstance;
use App\ExternalService\MediaManager\RadarrService;
use App\ExternalService\Metadata\TmdbService;
use App\Repository\MovieRepository;
use App\Repository\RadarrInstanceRepository;
use App\Service\MovieMatcherService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'scanarr:sync-radarr',
    description: 'Synchronize movies from all active Radarr instances and enrich with TMDB data',
)]
/** @SuppressWarnings(PHPMD.ExcessiveClassComplexity) */
class SyncRadarrCommand extends Command
{
    /** @SuppressWarnings(PHPMD.ExcessiveParameterList) */
    public function __construct(
        private readonly RadarrInstanceRepository $radarrInstanceRepository,
        private readonly MovieRepository $movieRepository,
        private readonly EntityManagerInterface $em,
        private readonly RadarrService $radarrService,
        private readonly TmdbService $tmdbService,
        private readonly MovieMatcherService $movieMatcherService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Synchronizing movies from Radarr instances');

        $instances = $this->radarrInstanceRepository->findBy(['isActive' => true]);

        if ($instances === []) {
            $io->warning('No active Radarr instances found.');

            return Command::SUCCESS;
        }

        $totals = $this->syncAllInstances($instances, $io);
        $this->outputSyncSummary($totals, $io);

        return Command::SUCCESS;
    }

    /**
     * @param RadarrInstance[] $instances
     *
     * @return array{imported: int, updated: int, enriched: int}
     */
    private function syncAllInstances(array $instances, SymfonyStyle $io): array
    {
        $totalImported = 0;
        $totalUpdated = 0;
        $totalEnriched = 0;

        foreach ($instances as $instance) {
            $io->section(sprintf('Syncing from: %s (%s)', $instance->getName(), $instance->getUrl()));

            try {
                $result = $this->syncInstance($instance, $io);
                $totalImported += $result['imported'];
                $totalUpdated += $result['updated'];
                $totalEnriched += $result['enriched'];
            } catch (Exception $e) {
                $this->logInstanceSyncError($instance, $e, $io);
            }
        }

        return ['imported' => $totalImported, 'updated' => $totalUpdated, 'enriched' => $totalEnriched];
    }

    /**
     * @param array{imported: int, updated: int, enriched: int} $totals
     */
    private function outputSyncSummary(array $totals, SymfonyStyle $io): void
    {
        $matchResult = $this->movieMatcherService->matchAll();

        $io->success(sprintf(
            'Sync complete: %d imported, %d updated, %d enriched via TMDB. Matching: %d via Radarr, %d via parse (%d total links).',
            $totals['imported'],
            $totals['updated'],
            $totals['enriched'],
            $matchResult['radarr_matched'],
            $matchResult['parse_matched'],
            $matchResult['total_links'],
        ));
    }

    private function logInstanceSyncError(RadarrInstance $instance, Exception $e, SymfonyStyle $io): void
    {
        $io->error(sprintf('Failed to sync %s: %s', $instance->getName(), $e->getMessage()));
        $this->logger->error('Radarr sync failed', [
            'instance' => $instance->getName(),
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * Synchronize a single Radarr instance.
     *
     * @return array{imported: int, updated: int, enriched: int}
     */
    private function syncInstance(RadarrInstance $instance, SymfonyStyle $io): array
    {
        $radarrMovies = $this->radarrService->getAllMovies($instance);
        $io->info(sprintf('Found %d movies in Radarr.', count($radarrMovies)));

        $this->refreshRootFolders($instance);

        $counters = ['imported' => 0, 'updated' => 0, 'enriched' => 0];
        $this->processRadarrMovies($radarrMovies, $instance, $io, $counters);
        $this->finalizeSyncInstance($instance, $counters, $io);

        return $counters;
    }

    private function refreshRootFolders(RadarrInstance $instance): void
    {
        try {
            $rootFolders = $this->radarrService->getRootFolders($instance);
            $instance->setRootFolders($rootFolders);
        } catch (Exception $e) {
            $this->logger->warning('Failed to refresh root folders', [
                'instance' => $instance->getName(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $radarrMovies
     * @param array{imported: int, updated: int, enriched: int} $counters
     */
    private function processRadarrMovies(
        array $radarrMovies,
        RadarrInstance $instance,
        SymfonyStyle $io,
        array &$counters,
    ): void {
        $batchCount = 0;

        foreach ($radarrMovies as $radarrMovie) {
            $processed = $this->processRadarrMovie($radarrMovie, $instance, $counters);

            if (!$processed) {
                continue;
            }

            ++$batchCount;
            if ($batchCount % 50 === 0) {
                $this->em->flush();
                $io->info(sprintf('Processed %d/%d movies...', $batchCount, count($radarrMovies)));
            }
        }
    }

    /**
     * Process a single Radarr movie entry.
     *
     * @param array<string, mixed> $radarrMovie
     * @param array{imported: int, updated: int, enriched: int} $counters
     */
    private function processRadarrMovie(array $radarrMovie, RadarrInstance $instance, array &$counters): bool
    {
        $tmdbId = $radarrMovie['tmdbId'] ?? null;
        $radarrId = $radarrMovie['id'] ?? null;
        $title = $radarrMovie['title'] ?? 'Unknown';

        if ($tmdbId === null && $radarrId === null) {
            return false;
        }

        $movie = $this->findOrCreateMovie($tmdbId, $title, $counters);
        $this->updateRadarrFields($movie, $radarrMovie, $instance);
        $this->updateBasicInfoFromRadarr($movie, $radarrMovie);
        $this->enrichWithTmdb($movie, $tmdbId, $title, $counters);

        return true;
    }

    /**
     * @param array{imported: int, updated: int, enriched: int} $counters
     */
    private function findOrCreateMovie(?int $tmdbId, string $title, array &$counters): Movie
    {
        if ($tmdbId !== null) {
            $movie = $this->movieRepository->findOneBy(['tmdbId' => $tmdbId]);
            if ($movie !== null) {
                ++$counters['updated'];

                return $movie;
            }
        }

        $movie = new Movie();
        $movie->setTitle($title);

        if ($tmdbId !== null) {
            $movie->setTmdbId($tmdbId);
        }

        $this->em->persist($movie);
        ++$counters['imported'];

        return $movie;
    }

    /**
     * @param array<string, mixed> $radarrMovie
     */
    private function updateRadarrFields(Movie $movie, array $radarrMovie, RadarrInstance $instance): void
    {
        $movie->setRadarrId($radarrMovie['id'] ?? null);
        $movie->setRadarrInstance($instance);
        $movie->setRadarrMonitored($radarrMovie['monitored'] ?? true);
        $movie->setRadarrHasFile($radarrMovie['hasFile'] ?? false);
    }

    /**
     * @param array<string, mixed> $radarrMovie
     */
    private function updateBasicInfoFromRadarr(Movie $movie, array $radarrMovie): void
    {
        $this->setIfEmpty($movie, 'OriginalTitle', $radarrMovie['originalTitle'] ?? null);
        $this->setIfNull($movie, 'Year', $radarrMovie['year'] ?? null);
        $this->setIfEmpty($movie, 'Synopsis', $radarrMovie['overview'] ?? null);
        $this->setIfNull($movie, 'RuntimeMinutes', $radarrMovie['runtime'] ?? null);
        $this->applyRadarrGenres($movie, $radarrMovie);
        $this->applyRadarrRating($movie, $radarrMovie);
    }

    /**
     * @param array<string, mixed> $radarrMovie
     */
    private function applyRadarrGenres(Movie $movie, array $radarrMovie): void
    {
        if (in_array($movie->getGenres(), [null, '', '0'], true) && !empty($radarrMovie['genres'])) {
            $movie->setGenres(implode(', ', $radarrMovie['genres']));
        }
    }

    /**
     * @param array<string, mixed> $radarrMovie
     */
    private function applyRadarrRating(Movie $movie, array $radarrMovie): void
    {
        if ($movie->getRating() !== null || empty($radarrMovie['ratings'])) {
            return;
        }

        $tmdbRating = $radarrMovie['ratings']['tmdb']['value'] ?? null;
        $imdbRating = $radarrMovie['ratings']['imdb']['value'] ?? null;
        $rating = $tmdbRating ?? $imdbRating;

        if ($rating !== null) {
            $movie->setRating((string)round((float)$rating, 1));
        }
    }

    /**
     * @param array{imported: int, updated: int, enriched: int} $counters
     */
    private function enrichWithTmdb(Movie $movie, ?int $tmdbId, string $title, array &$counters): void
    {
        if ($tmdbId === null) {
            return;
        }

        if ($movie->getPosterUrl() !== null && $movie->getSynopsis() !== null) {
            return;
        }

        try {
            $tmdbData = $this->tmdbService->enrichMovieData($tmdbId);

            if ($tmdbData !== []) {
                $this->applyTmdbData($movie, $tmdbData);
                ++$counters['enriched'];
            }
        } catch (Exception $e) {
            $this->logger->debug('TMDB enrichment failed', [
                'tmdb_id' => $tmdbId,
                'title' => $title,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array{imported: int, updated: int, enriched: int} $counters
     */
    private function finalizeSyncInstance(RadarrInstance $instance, array $counters, SymfonyStyle $io): void
    {
        $this->em->flush();

        $instance->setLastSyncAt(new DateTimeImmutable());
        $this->em->flush();

        $io->info(sprintf(
            'Instance %s: %d imported, %d updated, %d enriched.',
            $instance->getName(),
            $counters['imported'],
            $counters['updated'],
            $counters['enriched'],
        ));
    }

    /**
     * Apply TMDB enrichment data to a movie entity.
     *
     * @param array<string, mixed> $tmdbData
     */
    private function applyTmdbData(Movie $movie, array $tmdbData): void
    {
        $this->applyTmdbTextFields($movie, $tmdbData);
        $this->applyTmdbImageUrls($movie, $tmdbData);
        $this->applyTmdbMetadata($movie, $tmdbData);
    }

    /**
     * @param array<string, mixed> $tmdbData
     */
    private function applyTmdbTextFields(Movie $movie, array $tmdbData): void
    {
        $this->setIfEmptyOrZero($movie, 'Synopsis', $tmdbData['synopsis'] ?? null);

        // TMDB title in French - prefer it over Radarr English title
        if (!empty($tmdbData['title'])) {
            $movie->setTitle($tmdbData['title']);
        }

        $this->setIfEmptyOrZero($movie, 'OriginalTitle', $tmdbData['original_title'] ?? null);
    }

    /**
     * @param array<string, mixed> $tmdbData
     */
    private function applyTmdbImageUrls(Movie $movie, array $tmdbData): void
    {
        $this->setIfNull($movie, 'PosterUrl', $tmdbData['poster_url'] ?? null);
        $this->setIfNull($movie, 'BackdropUrl', $tmdbData['backdrop_url'] ?? null);
    }

    /**
     * @param array<string, mixed> $tmdbData
     */
    private function applyTmdbMetadata(Movie $movie, array $tmdbData): void
    {
        if (in_array($movie->getGenres(), [null, '', '0'], true) && !empty($tmdbData['genres'])) {
            $movie->setGenres($tmdbData['genres']);
        }

        if ($movie->getRating() === null && isset($tmdbData['rating'])) {
            $movie->setRating((string)$tmdbData['rating']);
        }

        $this->setIfNull($movie, 'RuntimeMinutes', $tmdbData['runtime_minutes'] ?? null);
        $this->setIfNull($movie, 'Year', $tmdbData['year'] ?? null);
    }

    private function setIfEmpty(Movie $movie, string $field, mixed $value): void
    {
        if ($value === null) {
            return;
        }

        $getter = 'get' . $field;
        if (empty($movie->$getter())) {
            $setter = 'set' . $field;
            $movie->$setter($value);
        }
    }

    private function setIfNull(Movie $movie, string $field, mixed $value): void
    {
        if ($value === null) {
            return;
        }

        $getter = 'get' . $field;
        if ($movie->$getter() === null) {
            $setter = 'set' . $field;
            $movie->$setter($value);
        }
    }

    private function setIfEmptyOrZero(Movie $movie, string $field, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $getter = 'get' . $field;
        if (in_array($movie->$getter(), [null, '', '0'], true)) {
            $setter = 'set' . $field;
            $movie->$setter($value);
        }
    }
}
