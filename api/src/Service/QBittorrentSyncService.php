<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\Matching\MatchResult;
use App\Entity\MediaFile;
use App\Entity\Movie;
use App\Entity\RadarrInstance;
use App\Entity\TorrentStat;
use App\Entity\TorrentStatHistory;
use App\Entity\TrackerRule;
use App\Enum\TorrentStatus;
use App\ExternalService\MediaManager\RadarrService;
use App\ExternalService\TorrentClient\QBittorrentService;
use App\Repository\MediaFileRepository;
use App\Repository\MovieRepository;
use App\Repository\RadarrInstanceRepository;
use App\Repository\SettingRepository;
use App\Repository\TorrentStatHistoryRepository;
use App\Repository\TorrentStatRepository;
use App\Repository\TrackerRuleRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 */
final class QBittorrentSyncService
{
    private const array MEDIA_EXTENSIONS = ['mkv', 'mp4', 'avi', 'm4v', 'ts', 'wmv'];

    /** @var array<string, TrackerRule> In-memory cache of tracker rules created during current sync */
    private array $trackerCache = [];

    /** @SuppressWarnings(PHPMD.ExcessiveParameterList) */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly QBittorrentService $qbitService,
        private readonly RadarrService $radarrService,
        private readonly FileMatchingService $fileMatchingService,
        private readonly MediaFileRepository $mediaFileRepository,
        private readonly MovieRepository $movieRepository,
        private readonly TorrentStatRepository $torrentStatRepository,
        private readonly TorrentStatHistoryRepository $historyRepository,
        private readonly TrackerRuleRepository $trackerRuleRepository,
        private readonly SettingRepository $settingRepository,
        private readonly RadarrInstanceRepository $radarrInstanceRepository,
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
        $result = $this->initSyncResult();

        if (!$this->qbitService->isConfigured()) {
            $this->logger->warning('qBittorrent is not configured, skipping sync');

            return $result;
        }

        $torrents = $this->fetchTorrents($result);
        if ($torrents === null) {
            return $result;
        }

        $hashToMovie = $this->buildRadarrHashMap();

        $this->processTorrents($torrents, $hashToMovie, $result);

        $result['stale_removed'] = $this->markStaleTorrents();
        $this->em->flush();
        $this->saveResult($result);

        return $result;
    }

    /** @return array{torrents_synced: int, new_trackers: int, unmatched: int, stale_removed: int, errors: int} */
    private function initSyncResult(): array
    {
        return [
            'torrents_synced' => 0,
            'new_trackers' => 0,
            'unmatched' => 0,
            'stale_removed' => 0,
            'errors' => 0,
        ];
    }

    /**
     * Fetch all torrents from qBittorrent. Returns null on failure.
     *
     * @param array<string, int> $result
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function fetchTorrents(array &$result): ?array
    {
        try {
            return $this->qbitService->getAllTorrents();
        } catch (Throwable $ex) {
            $this->logger->error('Failed to fetch torrents from qBittorrent', [
                'error' => $ex->getMessage(),
            ]);
            $result['errors'] = 1;
            $this->saveResult($result);

            return null;
        }
    }

    /**
     * Process each torrent from qBittorrent against known media files.
     *
     * @param array<int, array<string, mixed>> $torrents
     * @param array<string, array{radarrId: int, instance: RadarrInstance}> $hashToMovie
     * @param array<string, int> $result
     */
    private function processTorrents(array $torrents, array $hashToMovie, array &$result): void
    {
        foreach ($torrents as $torrent) {
            try {
                $this->processSingleTorrent($torrent, $hashToMovie, $result);
            } catch (Throwable $ex) {
                ++$result['errors'];
                $this->logger->error('Error processing torrent during sync', [
                    'hash' => $torrent['hash'] ?? 'unknown',
                    'error' => $ex->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param array<string, mixed> $torrent
     * @param array<string, array{radarrId: int, instance: RadarrInstance}> $hashToMovie
     * @param array<string, int> $result
     */
    private function processSingleTorrent(array $torrent, array $hashToMovie, array &$result): void
    {
        $hash = strtolower($torrent['hash'] ?? '');
        if ($hash === '') {
            return;
        }

        $domain = $this->extractTrackerDomain($torrent['tracker'] ?? '');
        $trackerRule = $this->autoDetectTracker($domain);
        if ($trackerRule instanceof TrackerRule && !$trackerRule->getId() instanceof Uuid) {
            ++$result['new_trackers'];
        }

        $mediaFile = $this->findMediaFileForTorrent($torrent, $hashToMovie);
        if (!$mediaFile instanceof MediaFile) {
            ++$result['unmatched'];
            $this->logger->debug('No media file match for torrent', [
                'hash' => $hash,
                'name' => $torrent['name'] ?? 'unknown',
            ]);

            return;
        }

        $stat = $this->createOrUpdateTorrentStat($torrent, $mediaFile, $domain);
        $this->maybeCreateSnapshot($stat);
        ++$result['torrents_synced'];
    }

    /**
     * Build a map of torrent hash -> Radarr movie info from all active Radarr instances.
     *
     * @return array<string, array{radarrId: int, instance: RadarrInstance}>
     */
    private function buildRadarrHashMap(): array
    {
        $hashToMovie = [];
        $instances = $this->radarrInstanceRepository->findBy(['isActive' => true]);

        foreach ($instances as $instance) {
            $this->mergeInstanceHistory($instance, $hashToMovie);
        }

        return $hashToMovie;
    }

    /** @param array<string, array{radarrId: int, instance: RadarrInstance}> $hashToMovie */
    private function mergeInstanceHistory(RadarrInstance $instance, array &$hashToMovie): void
    {
        try {
            $records = $this->radarrService->getHistory($instance);
        } catch (Throwable $ex) {
            $this->logger->warning('Failed to get Radarr history', [
                'instance' => $instance->getName(),
                'error' => $ex->getMessage(),
            ]);

            return;
        }

        foreach ($records as $record) {
            $this->applyHistoryRecord($record, $instance, $hashToMovie);
        }
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, array{radarrId: int, instance: RadarrInstance}> $hashToMovie
     */
    private function applyHistoryRecord(array $record, RadarrInstance $instance, array &$hashToMovie): void
    {
        $downloadId = $record['downloadId'] ?? null;
        $movieId = $record['movieId'] ?? null;
        if ($downloadId === null || $movieId === null) {
            return;
        }
        $hashToMovie[strtolower((string)$downloadId)] = [
            'radarrId' => $movieId,
            'instance' => $instance,
        ];
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
     */
    private function findMediaFileForTorrent(array $torrent, array $hashToMovie): ?MediaFile
    {
        $hash = strtolower($torrent['hash'] ?? '');

        // Priority 1: Radarr hash matching
        $match = $this->matchByRadarrHash($hash, $hashToMovie);
        if ($match instanceof MediaFile) {
            return $match;
        }

        // Priority 2: Content path matching (via FileMatchingService — progressive suffix)
        $match = $this->matchByContentPath($torrent['content_path'] ?? '');
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
     * Match torrent to media file via content_path using FileMatchingService.
     * Handles both file paths (single-file torrents) and directory paths (multi-file torrents).
     */
    private function matchByContentPath(string $contentPath): ?MediaFile
    {
        if ($contentPath === '') {
            return null;
        }

        $extension = strtolower(pathinfo($contentPath, PATHINFO_EXTENSION));

        // File path (has media extension) → direct suffix matching via FileMatchingService
        if (in_array($extension, self::MEDIA_EXTENSIONS, true)) {
            $matchResult = $this->fileMatchingService->match($contentPath);

            return $matchResult instanceof MatchResult ? $matchResult->mediaFile : null;
        }

        // Directory path (no media extension) → find files under this directory
        return $this->matchByDirectoryContentPath($contentPath);
    }

    /**
     * Match a directory content_path to a MediaFile by finding files under
     * progressively shorter directory suffixes. Returns the largest media file.
     *
     * Example: content_path "/data/torrents/movies/Movie.2024"
     *   Tries: "data/torrents/movies/Movie.2024" → LIKE '%…/%' → 0 results
     *          "torrents/movies/Movie.2024" → 0 results
     *          "movies/Movie.2024" → finds files → picks largest → MATCH
     */
    private function matchByDirectoryContentPath(string $directoryPath): ?MediaFile
    {
        $path = rtrim($directoryPath, '/');
        $segments = explode('/', ltrim($path, '/'));

        if ($segments === ['']) {
            return null;
        }

        for ($i = 0, $max = count($segments); $i < $max; ++$i) {
            $suffix = implode('/', array_slice($segments, $i));
            $results = $this->mediaFileRepository->findByFilePathUnderDirectory($suffix);

            if ($results === []) {
                continue;
            }

            // Deduplicate hardlinks, then pick the largest media file
            $unique = $this->deduplicateByInode($results);

            return $this->pickLargestFile($unique);
        }

        return null;
    }

    /**
     * Deduplicate MediaFiles that are hardlinks (same device_id + inode).
     *
     * @param MediaFile[] $files
     *
     * @return MediaFile[]
     */
    private function deduplicateByInode(array $files): array
    {
        $seen = [];
        $unique = [];

        foreach ($files as $file) {
            $deviceId = $file->getDeviceId();
            $inode = $file->getInode();

            if ($deviceId === null || $inode === null) {
                $unique[] = $file;

                continue;
            }

            $key = $deviceId . ':' . $inode;
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $file;
            }
        }

        return $unique;
    }

    /**
     * From a list of MediaFiles, return the one with the largest file size.
     *
     * @param MediaFile[] $files
     */
    private function pickLargestFile(array $files): ?MediaFile
    {
        $largest = null;
        $maxSize = -1;

        foreach ($files as $file) {
            $size = $file->getFileSizeBytes();
            if ($size > $maxSize) {
                $maxSize = $size;
                $largest = $file;
            }
        }

        return $largest;
    }

    /**
     * Match torrent to media file via file size (cross-seed fallback).
     *
     * @param array<string, mixed> $torrent
     */
    private function matchByFileSize(array $torrent): ?MediaFile
    {
        $largestSize = $this->findLargestMediaFileSize($torrent);
        if ($largestSize === 0) {
            return null;
        }

        $matches = $this->mediaFileRepository->findByFileSizeBytes($largestSize);

        if (count($matches) === 1) {
            return $matches[0];
        }

        return $this->resolveMultipleMatches($matches, $torrent['hash'] ?? '');
    }

    /**
     * Find the largest media file size from a torrent's file list.
     *
     * @param array<string, mixed> $torrent
     */
    private function findLargestMediaFileSize(array $torrent): int
    {
        try {
            $files = $this->qbitService->getTorrentFiles($torrent['hash'] ?? '');
        } catch (Throwable) {
            return 0;
        }

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

        return $largestSize;
    }

    /**
     * Try to resolve multiple size matches via partial hash comparison.
     *
     * @param array<MediaFile> $matches
     */
    private function resolveMultipleMatches(array $matches, string $torrentHash): ?MediaFile
    {
        foreach ($matches as $match) {
            if ($match->getFileHash() !== null && $match->getFileHash() === $torrentHash) {
                return $match;
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
        $stat = $this->findOrCreateTorrentStat($hash);

        $this->applyTorrentData($stat, $torrent, $mediaFile, $domain);
        $this->applyTorrentTimestamps($stat, $torrent);

        return $stat;
    }

    private function findOrCreateTorrentStat(string $hash): TorrentStat
    {
        $stat = $this->torrentStatRepository->findByHash($hash);
        if ($stat instanceof TorrentStat) {
            return $stat;
        }

        $stat = new TorrentStat();
        $stat->setTorrentHash($hash);
        $this->em->persist($stat);

        return $stat;
    }

    /** @param array<string, mixed> $torrent */
    private function applyTorrentData(TorrentStat $stat, array $torrent, MediaFile $mediaFile, string $domain): void
    {
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
    }

    /** @param array<string, mixed> $torrent */
    private function applyTorrentTimestamps(TorrentStat $stat, array $torrent): void
    {
        $addedOn = $torrent['added_on'] ?? null;
        if ($addedOn !== null && $addedOn > 0) {
            $stat->setAddedAt((new DateTimeImmutable())->setTimestamp((int)$addedOn));
        }

        $lastActivity = $torrent['last_activity'] ?? null;
        if ($lastActivity !== null && $lastActivity > 0) {
            $stat->setLastActivityAt((new DateTimeImmutable())->setTimestamp((int)$lastActivity));
        }
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
