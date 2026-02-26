<?php

namespace App\Command;

use App\Entity\Movie;
use App\Entity\RadarrInstance;
use App\Repository\MovieRepository;
use App\Repository\RadarrInstanceRepository;
use App\Service\RadarrService;
use App\Service\TmdbService;
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
class SyncRadarrCommand extends Command
{
    public function __construct(
        private readonly RadarrInstanceRepository $radarrInstanceRepository,
        private readonly MovieRepository $movieRepository,
        private readonly EntityManagerInterface $em,
        private readonly RadarrService $radarrService,
        private readonly TmdbService $tmdbService,
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
                $io->error(sprintf('Failed to sync %s: %s', $instance->getName(), $e->getMessage()));
                $this->logger->error('Radarr sync failed', [
                    'instance' => $instance->getName(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $io->success(sprintf(
            'Sync complete: %d imported, %d updated, %d enriched via TMDB.',
            $totalImported,
            $totalUpdated,
            $totalEnriched,
        ));

        return Command::SUCCESS;
    }

    /**
     * Synchronize a single Radarr instance.
     *
     * @return array{imported: int, updated: int, enriched: int}
     */
    private function syncInstance(RadarrInstance $instance, SymfonyStyle $io): array
    {
        $imported = 0;
        $updated = 0;
        $enriched = 0;

        // 1. Get all movies from Radarr
        $radarrMovies = $this->radarrService->getAllMovies($instance);
        $io->info(sprintf('Found %d movies in Radarr.', count($radarrMovies)));

        // 2. Also refresh root folders cache
        try {
            $rootFolders = $this->radarrService->getRootFolders($instance);
            $instance->setRootFolders($rootFolders);
        } catch (Exception $e) {
            $this->logger->warning('Failed to refresh root folders', [
                'instance' => $instance->getName(),
                'error' => $e->getMessage(),
            ]);
        }

        // 3. Process each movie
        $batchCount = 0;
        foreach ($radarrMovies as $radarrMovie) {
            $tmdbId = $radarrMovie['tmdbId'] ?? null;
            $radarrId = $radarrMovie['id'] ?? null;
            $title = $radarrMovie['title'] ?? 'Unknown';

            if ($tmdbId === null && $radarrId === null) {
                continue;
            }

            // Try to find existing movie by tmdb_id first
            $movie = null;
            if ($tmdbId !== null) {
                $movie = $this->movieRepository->findOneBy(['tmdbId' => $tmdbId]);
            }

            if ($movie === null) {
                // Create new movie
                $movie = new Movie();
                $movie->setTitle($title);

                if ($tmdbId !== null) {
                    $movie->setTmdbId($tmdbId);
                }

                $this->em->persist($movie);
                ++$imported;
            } else {
                ++$updated;
            }

            // Update Radarr-specific fields
            $movie->setRadarrId($radarrId);
            $movie->setRadarrInstance($instance);
            $movie->setRadarrMonitored($radarrMovie['monitored'] ?? true);
            $movie->setRadarrHasFile($radarrMovie['hasFile'] ?? false);

            // Update basic info from Radarr if not already set or if more complete
            if (empty($movie->getOriginalTitle()) && !empty($radarrMovie['originalTitle'])) {
                $movie->setOriginalTitle($radarrMovie['originalTitle']);
            }

            if ($movie->getYear() === null && !empty($radarrMovie['year'])) {
                $movie->setYear($radarrMovie['year']);
            }

            if (empty($movie->getSynopsis()) && !empty($radarrMovie['overview'])) {
                $movie->setSynopsis($radarrMovie['overview']);
            }

            if ($movie->getRuntimeMinutes() === null && !empty($radarrMovie['runtime'])) {
                $movie->setRuntimeMinutes($radarrMovie['runtime']);
            }

            // Extract genres from Radarr
            if (empty($movie->getGenres()) && !empty($radarrMovie['genres'])) {
                $movie->setGenres(implode(', ', $radarrMovie['genres']));
            }

            // Extract rating from Radarr ratings
            if ($movie->getRating() === null && !empty($radarrMovie['ratings'])) {
                $tmdbRating = $radarrMovie['ratings']['tmdb']['value'] ?? null;
                $imdbRating = $radarrMovie['ratings']['imdb']['value'] ?? null;
                $rating = $tmdbRating ?? $imdbRating;
                if ($rating !== null) {
                    $movie->setRating((string)round((float)$rating, 1));
                }
            }

            // 4. Enrich with TMDB if missing poster or synopsis
            if ($tmdbId !== null && ($movie->getPosterUrl() === null || $movie->getSynopsis() === null)) {
                try {
                    $tmdbData = $this->tmdbService->enrichMovieData($tmdbId);

                    if ($tmdbData !== []) {
                        $this->applyTmdbData($movie, $tmdbData);
                        ++$enriched;
                    }
                } catch (Exception $e) {
                    $this->logger->debug('TMDB enrichment failed', [
                        'tmdb_id' => $tmdbId,
                        'title' => $title,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Batch flush every 50 movies
            ++$batchCount;
            if ($batchCount % 50 === 0) {
                $this->em->flush();
                $io->info(sprintf('Processed %d/%d movies...', $batchCount, count($radarrMovies)));
            }
        }

        // Final flush
        $this->em->flush();

        // Update last sync timestamp
        $instance->setLastSyncAt(new DateTimeImmutable());
        $this->em->flush();

        $io->info(sprintf(
            'Instance %s: %d imported, %d updated, %d enriched.',
            $instance->getName(),
            $imported,
            $updated,
            $enriched,
        ));

        return [
            'imported' => $imported,
            'updated' => $updated,
            'enriched' => $enriched,
        ];
    }

    /**
     * Apply TMDB enrichment data to a movie entity.
     *
     * @param array<string, mixed> $tmdbData
     */
    private function applyTmdbData(Movie $movie, array $tmdbData): void
    {
        // Only update fields that are currently empty/null (don't overwrite existing data)
        if (in_array($movie->getSynopsis(), [null, '', '0'], true) && !empty($tmdbData['synopsis'])) {
            $movie->setSynopsis($tmdbData['synopsis']);
        }

        if ($movie->getPosterUrl() === null && !empty($tmdbData['poster_url'])) {
            $movie->setPosterUrl($tmdbData['poster_url']);
        }

        if ($movie->getBackdropUrl() === null && !empty($tmdbData['backdrop_url'])) {
            $movie->setBackdropUrl($tmdbData['backdrop_url']);
        }

        if (in_array($movie->getGenres(), [null, '', '0'], true) && !empty($tmdbData['genres'])) {
            $movie->setGenres($tmdbData['genres']);
        }

        if ($movie->getRating() === null && isset($tmdbData['rating'])) {
            $movie->setRating((string)$tmdbData['rating']);
        }

        if ($movie->getRuntimeMinutes() === null && !empty($tmdbData['runtime_minutes'])) {
            $movie->setRuntimeMinutes($tmdbData['runtime_minutes']);
        }

        if ($movie->getYear() === null && !empty($tmdbData['year'])) {
            $movie->setYear($tmdbData['year']);
        }

        // TMDB title in French - prefer it over Radarr English title
        if (!empty($tmdbData['title'])) {
            $movie->setTitle($tmdbData['title']);
        }

        if (in_array($movie->getOriginalTitle(), [null, '', '0'], true) && !empty($tmdbData['original_title'])) {
            $movie->setOriginalTitle($tmdbData['original_title']);
        }
    }
}
