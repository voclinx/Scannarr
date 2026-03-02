<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\MediaFile;
use App\Entity\Movie;
use App\Entity\ScheduledDeletion;
use App\Entity\ScheduledDeletionItem;
use App\Entity\TorrentStat;
use App\Entity\TrackerRule;
use App\Entity\User;
use App\Enum\TorrentStatus;
use App\Repository\MediaFileRepository;
use App\Repository\MovieRepository;
use App\Repository\TorrentStatRepository;
use App\Repository\TrackerRuleRepository;
use App\Repository\VolumeRepository;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 */
final readonly class SuggestionService
{
    /** @SuppressWarnings(PHPMD.ExcessiveParameterList) */
    public function __construct(
        private MovieRepository $movieRepository,
        private TorrentStatRepository $torrentStatRepository,
        private TrackerRuleRepository $trackerRuleRepository,
        private VolumeRepository $volumeRepository,
        private EntityManagerInterface $em,
        private DeletionService $deletionService,
        private MediaFileRepository $mediaFileRepository,
    ) {
    }

    /**
     * Get suggestion data (raw, no scoring — scoring is done on frontend).
     *
     * @param array<string, mixed> $filters
     *
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function getSuggestions(array $filters): array
    {
        $seedingStatus = $filters['seeding_status'] ?? 'all';
        $volumeId = $filters['volume_id'] ?? null;
        $excludeProtected = $filters['exclude_protected'] ?? true;
        $page = max(1, (int)($filters['page'] ?? 1));
        $perPage = min(100, max(1, (int)($filters['per_page'] ?? 50)));

        $trackerRules = [];
        foreach ($this->trackerRuleRepository->findAllOrderedByDomain() as $rule) {
            $trackerRules[$rule->getTrackerDomain()] = $rule;
        }

        [$suggestions, $totalSelectableSize, $trackersDetected] = $this->collectSuggestions(
            $trackerRules, $seedingStatus, $volumeId, $excludeProtected,
        );

        $meta = $this->buildResponseMeta($suggestions, $page, $perPage, $totalSelectableSize, $trackersDetected, $volumeId);
        $paginatedData = array_slice($suggestions, ($page - 1) * $perPage, $perPage);

        return ['data' => $paginatedData, 'meta' => $meta];
    }

    /**
     * @param array<string, TrackerRule> $trackerRules
     *
     * @return array{0: list<array<string, mixed>>, 1: int, 2: array<string, bool>}
     */
    private function collectSuggestions(array $trackerRules, string $seedingStatus, ?string $volumeId, bool $excludeProtected): array
    {
        $suggestions = [];
        $totalSelectableSize = 0;
        $trackersDetected = [];

        foreach ($this->movieRepository->findAll() as $movie) {
            $item = $this->buildSuggestionItem($movie, $trackerRules, $seedingStatus, $volumeId, $excludeProtected);
            if ($item !== null) {
                $suggestions[] = $item;
                $totalSelectableSize += $item['total_size_bytes'];
                $this->collectTrackersFromItem($item, $trackersDetected);
            }
        }

        foreach ($this->mediaFileRepository->findOrphansWithoutMovie($volumeId, $excludeProtected) as $mediaFile) {
            $item = $this->buildOrphanSuggestionItem($mediaFile, $trackerRules, $seedingStatus);
            if ($item !== null) {
                $suggestions[] = $item;
                $totalSelectableSize += $item['total_size_bytes'];
                $this->collectTrackersFromItem($item, $trackersDetected);
            }
        }

        return [$suggestions, $totalSelectableSize, $trackersDetected];
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, bool> $trackersDetected
     */
    private function collectTrackersFromItem(array $item, array &$trackersDetected): void
    {
        foreach ($item['files'] as $file) {
            foreach ($file['torrents'] as $torrent) {
                if ($torrent['tracker_domain'] !== null) {
                    $trackersDetected[$torrent['tracker_domain']] = true;
                }
            }
        }
    }

    /**
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     *
     * @param list<array<string, mixed>> $suggestions
     * @param array<string, bool> $trackersDetected
     *
     * @return array<string, mixed>
     */
    private function buildResponseMeta(array $suggestions, int $page, int $perPage, int $totalSelectableSize, array $trackersDetected, ?string $volumeId): array
    {
        $total = count($suggestions);

        $meta = [
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => max(1, (int)ceil($total / $perPage)),
            'summary' => [
                'total_selectable_size' => $totalSelectableSize,
                'trackers_detected' => array_keys($trackersDetected),
            ],
        ];

        $this->addVolumeSpaceToMeta($meta, $volumeId);

        return $meta;
    }

    /** @param array<string, mixed> $meta */
    private function addVolumeSpaceToMeta(array &$meta, ?string $volumeId): void
    {
        if ($volumeId === null) {
            return;
        }

        $volume = $this->volumeRepository->find($volumeId);
        if ($volume === null) {
            return;
        }

        $meta['volume_space'] = [
            'volume_id' => (string)$volume->getId(),
            'volume_name' => $volume->getName(),
            'used_space_bytes' => $volume->getUsedSpaceBytes(),
            'total_space_bytes' => $volume->getTotalSpaceBytes(),
        ];
    }

    /**
     * Batch delete movies immediately.
     *
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $options
     *
     * @return array{result: string, deletion_id?: string, status?: string, items_count?: int, message?: string}
     */
    public function batchDelete(array $items, array $options, User $user): array
    {
        if ($items === []) {
            return ['result' => 'empty_items'];
        }

        $deletion = new ScheduledDeletion();
        $deletion->setCreatedBy($user);
        $deletion->setScheduledDate(new DateTime('today'));
        $deletion->setDeletePhysicalFiles(true);
        $deletion->setDeleteRadarrReference((bool)($options['delete_radarr_reference'] ?? false));
        $deletion->setDisableRadarrAutoSearch((bool)($options['disable_radarr_auto_search'] ?? true));

        $itemsCount = $this->addItemsToDeletion($deletion, $items);
        if ($itemsCount === 0) {
            return ['result' => 'no_valid_items'];
        }

        $this->em->persist($deletion);
        $this->em->flush();

        $this->deletionService->executeDeletion($deletion);

        return [
            'result' => 'ok',
            'deletion_id' => (string)$deletion->getId(),
            'status' => $deletion->getStatus()->value,
            'items_count' => $itemsCount,
        ];
    }

    /**
     * Batch schedule movies for future deletion.
     *
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $options
     *
     * @return array{result: string, deletion_id?: string, scheduled_date?: string, status?: string, items_count?: int}
     */
    public function batchSchedule(string $scheduledDate, array $items, array $options, User $user): array
    {
        if ($items === []) {
            return ['result' => 'empty_items'];
        }

        $date = new DateTime($scheduledDate);
        if ($date < new DateTime('today')) {
            return ['result' => 'past_date'];
        }

        $deletion = $this->buildScheduledDeletion($date, $options, $user);
        $itemsCount = $this->addItemsToDeletion($deletion, $items);
        if ($itemsCount === 0) {
            return ['result' => 'no_valid_items'];
        }

        $this->em->persist($deletion);
        $this->em->flush();

        return [
            'result' => 'ok',
            'deletion_id' => (string)$deletion->getId(),
            'scheduled_date' => $deletion->getScheduledDate()->format('Y-m-d'),
            'status' => $deletion->getStatus()->value,
            'items_count' => $itemsCount,
        ];
    }

    /** @param array<string, mixed> $options */
    private function buildScheduledDeletion(DateTime $date, array $options, User $user): ScheduledDeletion
    {
        $deletion = new ScheduledDeletion();
        $deletion->setCreatedBy($user);
        $deletion->setScheduledDate($date);
        $deletion->setDeletePhysicalFiles(true);
        $deletion->setDeleteRadarrReference((bool)($options['delete_radarr_reference'] ?? false));
        $deletion->setDisableRadarrAutoSearch((bool)($options['disable_radarr_auto_search'] ?? true));

        return $deletion;
    }

    /**
     * Add items to a ScheduledDeletion. Returns count of valid items added.
     *
     * @param array<int, array<string, mixed>> $items
     */
    private function addItemsToDeletion(ScheduledDeletion $deletion, array $items): int
    {
        $count = 0;
        foreach ($items as $itemData) {
            $movieId = $itemData['movie_id'] ?? null;
            $fileIds = $itemData['file_ids'] ?? [];

            if ($fileIds === []) {
                continue;
            }

            $movie = null;
            if ($movieId !== null) {
                $movie = $this->movieRepository->find($movieId);
                if (!$movie instanceof Movie) {
                    continue;
                }
            }

            // Orphan items (movie_id = null) are allowed — ScheduledDeletionItem.movie is nullable
            $item = new ScheduledDeletionItem();
            $item->setMovie($movie);
            $item->setMediaFileIds($fileIds);
            $deletion->addItem($item);
            ++$count;
        }

        return $count;
    }

    /**
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     *
     * Build a suggestion item for a movie. Returns null if filtered out.
     *
     * @param array<string, TrackerRule> $trackerRules
     *
     * @return array<string, mixed>|null
     */
    private function buildSuggestionItem(
        Movie $movie,
        array $trackerRules,
        string $seedingStatus,
        ?string $volumeId,
        bool $excludeProtected,
    ): ?array {
        if ($excludeProtected && $movie->isProtected()) {
            return null;
        }

        if ($movie->getMovieFiles()->isEmpty()) {
            return null;
        }

        [$filesData, $totalFileSize] = $this->buildFilesData($movie, $trackerRules, $volumeId, $excludeProtected);

        if ($filesData === []) {
            return null;
        }

        $movieSeedingStatus = $this->calculateMovieSeedingStatus($filesData);

        if ($seedingStatus === 'orphans_only' && $movieSeedingStatus !== 'orphan') {
            return null;
        }
        if ($seedingStatus === 'seeding_only' && $movieSeedingStatus !== 'seeding') {
            return null;
        }

        $hasRadarr = $movie->getRadarrId() !== null;
        $stats = $this->aggregateFilesStats($filesData);
        $totalFreedBytes = array_sum(array_column($filesData, 'real_freed_bytes'));

        return [
            'movie' => $this->buildMovieInfo($movie),
            'files' => $filesData,
            'seeding_status' => $movieSeedingStatus,
            'total_size_bytes' => $totalFileSize,
            'total_freed_bytes' => $totalFreedBytes,
            'best_ratio' => $stats['best_ratio'],
            'worst_ratio' => $stats['worst_ratio'],
            'total_seed_time_max_seconds' => $stats['max_seed_time'],
            'cross_seed_count' => $stats['cross_seed_count'],
            'blocked_by_tracker_rules' => $stats['blocked'],
            'file_count' => count($filesData),
            'is_in_radarr' => $hasRadarr,
            'is_in_torrent_client' => $stats['has_torrent_client'],
            'is_in_media_player' => $stats['has_media_player'],
        ];
    }

    /**
     * @param array<string, TrackerRule> $trackerRules
     *
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function buildFilesData(Movie $movie, array $trackerRules, ?string $volumeId, bool $excludeProtected): array
    {
        $filesData = [];
        $totalFileSize = 0;

        foreach ($movie->getMovieFiles() as $mf) {
            $mediaFile = $mf->getMediaFile();
            if ($mediaFile === null) {
                continue;
            }
            if ($excludeProtected && $mediaFile->isProtected()) {
                continue;
            }
            if ($volumeId !== null && (string)$mediaFile->getVolume()?->getId() !== $volumeId) {
                continue;
            }

            $torrents = $this->getFileTorrents($mediaFile);
            $trackerCheck = $this->isBlockedByTrackerRules($torrents, $trackerRules);

            $filesData[] = $this->buildSingleFileData($mediaFile, $torrents, $trackerCheck, $this->calculateRealFreedBytes($mediaFile));
            $totalFileSize += $mediaFile->getFileSizeBytes();
        }

        return [$filesData, $totalFileSize];
    }

    /** @return array<string, mixed> */
    private function buildMovieInfo(Movie $movie): array
    {
        return [
            'id' => (string)$movie->getId(),
            'title' => $movie->getTitle(),
            'year' => $movie->getYear(),
            'poster_url' => $movie->getPosterUrl(),
            'genres' => $movie->getGenres(),
            'rating' => $movie->getRating() !== null ? (float)$movie->getRating() : null,
            'is_protected' => $movie->isProtected(),
        ];
    }

    /**
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     *
     * @param array<int, array<string, mixed>> $filesData
     *
     * @return array{best_ratio: ?float, worst_ratio: ?float, max_seed_time: int, cross_seed_count: int, blocked: bool, has_torrent_client: bool, has_media_player: bool}
     */
    private function aggregateFilesStats(array $filesData): array
    {
        $bestRatio = null;
        $worstRatio = null;
        $maxSeedTime = 0;
        $totalCrossSeedCount = 0;
        $isBlockedByAnyRule = false;
        $hasTorrentClient = false;
        $hasMediaPlayer = false;

        foreach ($filesData as $file) {
            $totalCrossSeedCount += $file['cross_seed_count'];
            $isBlockedByAnyRule = $isBlockedByAnyRule || $file['blocked_by_tracker_rules'];
            $hasTorrentClient = $hasTorrentClient || $file['is_in_torrent_client'];
            $hasMediaPlayer = $hasMediaPlayer || $file['is_in_media_player'];

            foreach ($file['torrents'] as $torrent) {
                $ratio = $torrent['ratio'];
                $bestRatio = $bestRatio === null || $ratio > $bestRatio ? $ratio : $bestRatio;
                $worstRatio = $worstRatio === null || $ratio < $worstRatio ? $ratio : $worstRatio;
                $maxSeedTime = max($maxSeedTime, $torrent['seed_time_seconds']);
            }
        }

        return [
            'best_ratio' => $bestRatio,
            'worst_ratio' => $worstRatio,
            'max_seed_time' => $maxSeedTime,
            'cross_seed_count' => $totalCrossSeedCount,
            'blocked' => $isBlockedByAnyRule,
            'has_torrent_client' => $hasTorrentClient,
            'has_media_player' => $hasMediaPlayer,
        ];
    }

    /**
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     *
     * Build a suggestion item for an orphan file (no movie link).
     *
     * @param array<string, TrackerRule> $trackerRules
     *
     * @return array<string, mixed>|null
     */
    private function buildOrphanSuggestionItem(
        MediaFile $mediaFile,
        array $trackerRules,
        string $seedingStatus,
    ): ?array {
        if ($mediaFile->isProtected()) {
            return null;
        }

        $torrents = $this->getFileTorrents($mediaFile);
        $fileSeedingStatus = $this->calculateSeedingStatus($torrents);

        if ($seedingStatus === 'orphans_only' && $fileSeedingStatus !== 'orphan') {
            return null;
        }
        if ($seedingStatus === 'seeding_only' && $fileSeedingStatus !== 'seeding') {
            return null;
        }

        $trackerCheck = $this->isBlockedByTrackerRules($torrents, $trackerRules);
        $realFreedBytes = $this->calculateRealFreedBytes($mediaFile);
        $baseName = pathinfo((string)$mediaFile->getFileName(), PATHINFO_FILENAME);
        $displayTitle = preg_replace('/\s+/', ' ', str_replace(['.', '_'], ' ', $baseName));

        [$bestRatio, $worstRatio, $maxSeedTime] = $this->computeTorrentRatioStats($torrents);
        $fileData = $this->buildSingleFileData($mediaFile, $torrents, $trackerCheck, $realFreedBytes);
        $fileData['seeding_status'] = $fileSeedingStatus;

        return [
            'movie' => [
                'id' => null,
                'title' => $displayTitle,
                'year' => null,
                'poster_url' => null,
                'genres' => null,
                'rating' => null,
                'is_protected' => false,
            ],
            'files' => [$fileData],
            'seeding_status' => $fileSeedingStatus,
            'total_size_bytes' => $mediaFile->getFileSizeBytes(),
            'total_freed_bytes' => $realFreedBytes,
            'best_ratio' => $bestRatio,
            'worst_ratio' => $worstRatio,
            'total_seed_time_max_seconds' => $maxSeedTime,
            'cross_seed_count' => count($torrents),
            'blocked_by_tracker_rules' => $trackerCheck['blocked'],
            'file_count' => 1,
            'is_in_radarr' => false,
            'is_in_torrent_client' => $torrents !== [],
            'is_in_media_player' => $mediaFile->isLinkedMediaPlayer(),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $torrents
     *
     * @return array{0: ?float, 1: ?float, 2: int}
     */
    private function computeTorrentRatioStats(array $torrents): array
    {
        $bestRatio = null;
        $worstRatio = null;
        $maxSeedTime = 0;

        foreach ($torrents as $torrent) {
            $ratio = $torrent['ratio'];
            $bestRatio = $bestRatio === null || $ratio > $bestRatio ? $ratio : $bestRatio;
            $worstRatio = $worstRatio === null || $ratio < $worstRatio ? $ratio : $worstRatio;
            $maxSeedTime = max($maxSeedTime, $torrent['seed_time_seconds']);
        }

        return [$bestRatio, $worstRatio, $maxSeedTime];
    }

    /**
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     *
     * @param array<int, array<string, mixed>> $torrents
     * @param array{blocked: bool, reason: ?string} $trackerCheck
     *
     * @return array<string, mixed>
     */
    private function buildSingleFileData(MediaFile $mediaFile, array $torrents, array $trackerCheck, int $realFreedBytes): array
    {
        return [
            'id' => (string)$mediaFile->getId(),
            'file_name' => $mediaFile->getFileName(),
            'file_path' => $mediaFile->getFilePath(),
            'file_size_bytes' => $mediaFile->getFileSizeBytes(),
            'hardlink_count' => $mediaFile->getHardlinkCount(),
            'inode' => $mediaFile->getInode(),
            'device_id' => $mediaFile->getDeviceId(),
            'real_freed_bytes' => $realFreedBytes,
            'volume_id' => (string)$mediaFile->getVolume()?->getId(),
            'volume_name' => $mediaFile->getVolume()?->getName(),
            'resolution' => $mediaFile->getResolution(),
            'codec' => $mediaFile->getCodec(),
            'is_protected' => $mediaFile->isProtected(),
            'partial_hash' => $mediaFile->getPartialHash(),
            'seeding_status' => $this->calculateSeedingStatus($torrents),
            'cross_seed_count' => count($torrents),
            'blocked_by_tracker_rules' => $trackerCheck['blocked'],
            'tracker_block_reason' => $trackerCheck['reason'],
            'is_in_radarr' => $mediaFile->isLinkedRadarr(),
            'is_in_torrent_client' => $torrents !== [],
            'is_in_media_player' => $mediaFile->isLinkedMediaPlayer(),
            'torrents' => $torrents,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function getFileTorrents(MediaFile $file): array
    {
        $deviceId = $file->getDeviceId();
        $inode = $file->getInode();

        if ($deviceId === null || $inode === null) {
            return array_map($this->serializeTorrentStat(...), $this->torrentStatRepository->findByMediaFile($file));
        }

        return array_map($this->serializeTorrentStat(...), $this->torrentStatRepository->findByInode($deviceId, $inode));
    }

    /** @return array<string, mixed> */
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

    private function calculateSeedingStatus(array $torrents): string
    {
        if ($torrents === []) {
            return 'orphan';
        }

        $activeStatuses = [TorrentStatus::SEEDING->value, TorrentStatus::STALLED->value];
        $hasActive = false;
        $allPaused = true;

        foreach ($torrents as $torrent) {
            if (in_array($torrent['status'], $activeStatuses, true)) {
                $hasActive = true;
                $allPaused = false;
            } elseif ($torrent['status'] !== TorrentStatus::PAUSED->value) {
                $allPaused = false;
            }
        }

        if ($hasActive) {
            return 'seeding';
        }
        if ($allPaused) {
            return 'paused';
        }

        return 'completed';
    }

    private function calculateMovieSeedingStatus(array $filesData): string
    {
        $statuses = array_unique(array_column($filesData, 'seeding_status'));

        return count($statuses) === 1 ? $statuses[0] : 'mixed';
    }

    /**
     * @param array<int, array<string, mixed>> $torrents
     * @param array<string, TrackerRule> $trackerRules
     *
     * @return array{blocked: bool, reason: ?string}
     */
    private function isBlockedByTrackerRules(array $torrents, array $trackerRules): array
    {
        foreach ($torrents as $torrent) {
            $domain = $torrent['tracker_domain'] ?? null;
            if ($domain === null) {
                continue;
            }
            if (!isset($trackerRules[$domain])) {
                continue;
            }

            $rule = $trackerRules[$domain];
            $ratio = $torrent['ratio'];
            $seedTimeHours = $torrent['seed_time_seconds'] / 3600;
            $minRatio = (float)$rule->getMinRatio();
            $minSeedTimeHours = $rule->getMinSeedTimeHours();

            if ($minRatio > 0 && $ratio < $minRatio) {
                return ['blocked' => true, 'reason' => sprintf('Ratio %.4f < minimum %.4f for %s', $ratio, $minRatio, $domain)];
            }
            if ($minSeedTimeHours > 0 && $seedTimeHours < $minSeedTimeHours) {
                return ['blocked' => true, 'reason' => sprintf('Seed time %.1fh < minimum %dh for %s', $seedTimeHours, $minSeedTimeHours, $domain)];
            }
        }

        return ['blocked' => false, 'reason' => null];
    }

    private function calculateRealFreedBytes(MediaFile $file): int
    {
        $nlink = $file->getHardlinkCount();

        if ($nlink <= 1) {
            return $file->getFileSizeBytes();
        }

        $deviceId = $file->getDeviceId();
        $inode = $file->getInode();

        if ($deviceId === null || $inode === null) {
            // No inode info → pessimistic: assume space is not freed
            return 0;
        }

        $knownSiblings = count($this->mediaFileRepository->findAllByInode($deviceId, $inode));

        // If Scanarr knows at least as many paths as nlink, deleting all of them frees the space.
        return $knownSiblings >= $nlink ? $file->getFileSizeBytes() : 0;
    }
}
