<?php

namespace App\Service;

use App\Entity\MediaFile;
use App\Entity\Movie;
use App\Entity\RadarrInstance;
use App\Entity\TorrentStat;
use App\Entity\TorrentStatHistory;
use App\Entity\TrackerRule;
use App\Entity\Volume;
use App\Enum\TorrentStatus;
use App\Repository\MediaFileRepository;
use App\Repository\MovieRepository;
use App\Repository\RadarrInstanceRepository;
use App\Repository\SettingRepository;
use App\Repository\TorrentStatHistoryRepository;
use App\Repository\TorrentStatRepository;
use App\Repository\TrackerRuleRepository;
use App\Repository\VolumeRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

class QBittorrentSyncService
{
    private const array MEDIA_EXTENSIONS = ['mkv', 'mp4', 'avi', 'm4v', 'ts', 'wmv'];

    /** @var array<string, TrackerRule> In-memory cache of tracker rules created during current sync */
    private array $trackerCache = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly QBittorrentService $qbitService,
        private readonly RadarrService $radarrService,
        private readonly MediaFileRepository $mediaFileRepository,
        private readonly MovieRepository $movieRepository,
        private readonly TorrentStatRepository $torrentStatRepository,
        private readonly TorrentStatHistoryRepository $historyRepository,
        private readonly TrackerRuleRepository $trackerRuleRepository,
        private readonly SettingRepository $settingRepository,
        private readonly RadarrInstanceRepository $radarrInstanceRepository,
        private readonly VolumeRepository $volumeRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Main sync method: pulls torrents from qBittorrent and matches them to media files.
     *
     * @return array{torrents_synced: int, new_trackers: int, unmatched: int, stale_removed: int, errors: int}
     */
    public function sync(): array
    {
        $this->trackerCache = [];

        $result = [
            'torrents_synced' => 0,
            'new_trackers' => 0,
            'unmatched' => 0,
            'stale_removed' => 0,
            'errors' => 0,
        ];

        if (!$this->qbitService->isConfigured()) {
            $this->logger->warning('qBittorrent is not configured, skipping sync');

            return $result;
        }

        try {
            $torrents = $this->qbitService->getAllTorrents();
        } catch (Throwable $e) {
            $this->logger->error('Failed to fetch torrents from qBittorrent', [
                'error' => $e->getMessage(),
            ]);
            $result['errors'] = 1;
            $this->saveResult($result);

            return $result;
        }

        // Build Radarr hash→movie map
        $hashToMovie = $this->buildRadarrHashMap();

        // Load all volumes for path matching
        $volumes = $this->volumeRepository->findAllActive();

        $seenHashes = [];

        foreach ($torrents as $torrent) {
            try {
                $hash = strtolower($torrent['hash'] ?? '');
                if ($hash === '') {
                    continue;
                }

                $domain = $this->extractTrackerDomain($torrent['tracker'] ?? '');

                // Auto-detect tracker
                $trackerRule = $this->autoDetectTracker($domain);
                if ($trackerRule instanceof TrackerRule && !$trackerRule->getId() instanceof Uuid) {
                    ++$result['new_trackers'];
                }

                // Match torrent to media file
                $mediaFile = $this->findMediaFileForTorrent($torrent, $hashToMovie, $volumes);

                if ($mediaFile instanceof MediaFile) {
                    $stat = $this->createOrUpdateTorrentStat($torrent, $mediaFile, $domain);
                    $this->maybeCreateSnapshot($stat);
                    $seenHashes[] = $hash;
                    ++$result['torrents_synced'];
                } else {
                    ++$result['unmatched'];
                    $this->logger->debug('No media file match for torrent', [
                        'hash' => $hash,
                        'name' => $torrent['name'] ?? 'unknown',
                    ]);
                }
            } catch (Throwable $e) {
                ++$result['errors'];
                $this->logger->error('Error processing torrent during sync', [
                    'hash' => $torrent['hash'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Mark stale torrents as REMOVED (absent for 3+ sync intervals = 90 minutes)
        $result['stale_removed'] = $this->markStaleTorrents();

        $this->em->flush();
        $this->saveResult($result);

        return $result;
    }

    /**
     * Build a map of torrent hash → Radarr movie info from all active Radarr instances.
     *
     * @return array<string, array{radarrId: int, instance: RadarrInstance}>
     */
    private function buildRadarrHashMap(): array
    {
        $hashToMovie = [];

        $instances = $this->radarrInstanceRepository->findBy(['isActive' => true]);

        foreach ($instances as $instance) {
            try {
                $records = $this->radarrService->getHistory($instance);

                foreach ($records as $record) {
                    $downloadId = $record['downloadId'] ?? null;
                    $movieId = $record['movieId'] ?? null;

                    if ($downloadId !== null && $movieId !== null) {
                        $hashToMovie[strtolower((string)$downloadId)] = [
                            'radarrId' => $movieId,
                            'instance' => $instance,
                        ];
                    }
                }
            } catch (Throwable $e) {
                $this->logger->warning('Failed to get Radarr history', [
                    'instance' => $instance->getName(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $hashToMovie;
    }

    /**
     * Extract tracker domain from a tracker URL.
     */
    private function extractTrackerDomain(string $trackerUrl): string
    {
        if ($trackerUrl === '') {
            return '';
        }

        $host = parse_url($trackerUrl, PHP_URL_HOST);

        return $host !== null && $host !== false ? $host : '';
    }

    /**
     * Auto-detect tracker: find or create a TrackerRule for the given domain.
     */
    private function autoDetectTracker(string $domain): ?TrackerRule
    {
        if ($domain === '') {
            return null;
        }

        // Check in-memory cache first (prevents duplicate INSERTs before flush)
        if (isset($this->trackerCache[$domain])) {
            return $this->trackerCache[$domain];
        }

        $existing = $this->trackerRuleRepository->findByDomain($domain);

        if ($existing instanceof TrackerRule) {
            $this->trackerCache[$domain] = $existing;

            return $existing;
        }

        $rule = new TrackerRule();
        $rule->setTrackerDomain($domain);
        $rule->setMinSeedTimeHours(0);
        $rule->setMinRatio('0.0000');
        $rule->setIsAutoDetected(true);

        $this->em->persist($rule);
        $this->trackerCache[$domain] = $rule;

        return $rule;
    }

    /**
     * Find the media file matching a torrent using 3 priority strategies.
     *
     * @param array<string, mixed> $torrent
     * @param array<string, array{radarrId: int, instance: RadarrInstance}> $hashToMovie
     * @param array<Volume> $volumes
     */
    private function findMediaFileForTorrent(array $torrent, array $hashToMovie, array $volumes): ?MediaFile
    {
        $hash = strtolower($torrent['hash'] ?? '');

        // Priority 1: Radarr hash matching
        $match = $this->matchByRadarrHash($hash, $hashToMovie);
        if ($match instanceof MediaFile) {
            return $match;
        }

        // Priority 2: Content path matching
        $match = $this->matchByContentPath($torrent['content_path'] ?? '', $volumes);
        if ($match instanceof MediaFile) {
            return $match;
        }

        // Priority 3: Cross-seed fallback (file size matching)
        return $this->matchByFileSize($torrent);
    }

    /**
     * Match torrent to media file via Radarr download history hash.
     *
     * @param array<string, array{radarrId: int, instance: RadarrInstance}> $hashToMovie
     */
    private function matchByRadarrHash(string $hash, array $hashToMovie): ?MediaFile
    {
        $info = $hashToMovie[$hash] ?? null;

        if ($info === null) {
            return null;
        }

        $movie = $this->movieRepository->findByRadarrIdAndInstance($info['radarrId'], $info['instance']);

        if (!$movie instanceof Movie) {
            return null;
        }

        $movieFiles = $movie->getMovieFiles();

        if ($movieFiles->isEmpty()) {
            return null;
        }

        return $movieFiles->first()->getMediaFile();
    }

    /**
     * Match torrent to media file via content_path → host path → volume resolution.
     *
     * @param array<Volume> $volumes
     */
    private function matchByContentPath(string $contentPath, array $volumes): ?MediaFile
    {
        if ($contentPath === '') {
            return null;
        }

        $hostPath = $this->qbitService->mapQbitPathToHost($contentPath);

        foreach ($volumes as $volume) {
            $volumeHostPath = rtrim((string)$volume->getHostPath(), '/');

            if (!str_starts_with($hostPath, $volumeHostPath . '/')) {
                continue;
            }

            $relativePath = substr($hostPath, strlen($volumeHostPath) + 1);
            $mediaFile = $this->mediaFileRepository->findByVolumeAndFilePath($volume, $relativePath);

            if ($mediaFile instanceof MediaFile) {
                return $mediaFile;
            }
        }

        return null;
    }

    /**
     * Match torrent to media file via file size (cross-seed fallback).
     *
     * @param array<string, mixed> $torrent
     */
    private function matchByFileSize(array $torrent): ?MediaFile
    {
        try {
            $files = $this->qbitService->getTorrentFiles($torrent['hash'] ?? '');
        } catch (Throwable) {
            return null;
        }

        // Filter for media files and find the largest
        $largestSize = 0;

        foreach ($files as $file) {
            $name = $file['name'] ?? '';
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            if (!in_array($extension, self::MEDIA_EXTENSIONS, true)) {
                continue;
            }

            $size = (int)($file['size'] ?? 0);
            if ($size > $largestSize) {
                $largestSize = $size;
            }
        }

        if ($largestSize === 0) {
            return null;
        }

        $matches = $this->mediaFileRepository->findByFileSizeBytes($largestSize);

        if (count($matches) === 1) {
            return $matches[0];
        }

        // Multiple matches: try partial_hash comparison if available
        if (count($matches) > 1) {
            $torrentFileHash = $torrent['hash'] ?? '';

            foreach ($matches as $match) {
                if ($match->getFileHash() !== null && $match->getFileHash() === $torrentFileHash) {
                    return $match;
                }
            }
        }

        return null;
    }

    /**
     * Create or update a TorrentStat entity from qBittorrent data.
     *
     * @param array<string, mixed> $torrent
     */
    private function createOrUpdateTorrentStat(array $torrent, MediaFile $mediaFile, string $domain): TorrentStat
    {
        $hash = strtolower($torrent['hash'] ?? '');
        $stat = $this->torrentStatRepository->findByHash($hash);

        if (!$stat instanceof TorrentStat) {
            $stat = new TorrentStat();
            $stat->setTorrentHash($hash);
            $this->em->persist($stat);
        }

        $stat->setMediaFile($mediaFile);
        $stat->setTorrentName($torrent['name'] ?? null);
        $stat->setTrackerDomain($domain !== '' ? $domain : null);
        $stat->setRatio(number_format((float)($torrent['ratio'] ?? 0), 4, '.', ''));
        $stat->setSeedTimeSeconds((int)($torrent['seeding_time'] ?? 0));
        $stat->setUploadedBytes((int)($torrent['uploaded'] ?? 0));
        $stat->setDownloadedBytes((int)($torrent['downloaded'] ?? 0));
        $stat->setSizeBytes((int)($torrent['size'] ?? 0));
        $stat->setStatus($this->mapQbitStatus($torrent['state'] ?? ''));
        $stat->setQbitContentPath($torrent['content_path'] ?? null);
        $stat->setLastSyncedAt(new DateTimeImmutable());

        // Set addedAt from qBit's added_on (unix timestamp)
        $addedOn = $torrent['added_on'] ?? null;
        if ($addedOn !== null && $addedOn > 0) {
            $stat->setAddedAt((new DateTimeImmutable())->setTimestamp((int)$addedOn));
        }

        // Set lastActivityAt from qBit's last_activity (unix timestamp)
        $lastActivity = $torrent['last_activity'] ?? null;
        if ($lastActivity !== null && $lastActivity > 0) {
            $stat->setLastActivityAt((new DateTimeImmutable())->setTimestamp((int)$lastActivity));
        }

        return $stat;
    }

    /**
     * Create a daily snapshot if one doesn't already exist.
     */
    private function maybeCreateSnapshot(TorrentStat $stat): void
    {
        if ($this->historyRepository->hasSnapshotToday($stat)) {
            return;
        }

        $snapshot = new TorrentStatHistory();
        $snapshot->setTorrentStat($stat);
        $snapshot->setRatio($stat->getRatio());
        $snapshot->setUploadedBytes($stat->getUploadedBytes());
        $snapshot->setSeedTimeSeconds($stat->getSeedTimeSeconds());

        $this->em->persist($snapshot);
    }

    /**
     * Mark torrents not seen in the last 90 minutes as REMOVED.
     */
    private function markStaleTorrents(): int
    {
        $cutoff = new DateTimeImmutable('-90 minutes');
        $staleTorrents = $this->torrentStatRepository->findNotSeenSince($cutoff);
        $count = 0;

        foreach ($staleTorrents as $stat) {
            if ($stat->getStatus() !== TorrentStatus::REMOVED) {
                $stat->setStatus(TorrentStatus::REMOVED);
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Map qBittorrent state to our TorrentStatus enum.
     */
    private function mapQbitStatus(string $qbitState): TorrentStatus
    {
        return match ($qbitState) {
            'uploading', 'stalledUP', 'forcedUP', 'queuedUP', 'checkingUP' => TorrentStatus::SEEDING,
            'pausedUP', 'pausedDL' => TorrentStatus::PAUSED,
            'stalledDL', 'queuedDL', 'checkingDL', 'downloading', 'forcedDL', 'metaDL', 'allocating' => TorrentStatus::STALLED,
            'error', 'missingFiles' => TorrentStatus::ERROR,
            'checkingResumeData', 'moving' => TorrentStatus::SEEDING,
            default => TorrentStatus::SEEDING,
        };
    }

    /**
     * Save sync result to settings.
     *
     * @param array<string, int> $result
     */
    private function saveResult(array $result): void
    {
        $this->settingRepository->setValue(
            'qbittorrent_last_sync_at',
            (new DateTimeImmutable())->format('c'),
        );
        $this->settingRepository->setValue(
            'qbittorrent_last_sync_result',
            json_encode($result, JSON_THROW_ON_ERROR),
            'json',
        );
    }
}
