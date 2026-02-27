<?php

namespace App\Service;

use App\Entity\MediaFile;
use App\Entity\Movie;
use App\Entity\TorrentStat;
use App\Entity\TrackerRule;
use App\Enum\TorrentStatus;
use App\Repository\MovieRepository;
use App\Repository\TorrentStatRepository;
use App\Repository\TrackerRuleRepository;
use App\Repository\VolumeRepository;

class SuggestionService
{
    public function __construct(
        private readonly MovieRepository $movieRepository,
        private readonly TorrentStatRepository $torrentStatRepository,
        private readonly TrackerRuleRepository $trackerRuleRepository,
        private readonly VolumeRepository $volumeRepository,
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

        // Load all tracker rules indexed by domain for fast lookup
        $trackerRules = [];
        foreach ($this->trackerRuleRepository->findAllOrderedByDomain() as $rule) {
            $trackerRules[$rule->getTrackerDomain()] = $rule;
        }

        // Load all movies with their files
        $allMovies = $this->movieRepository->findAll();

        $suggestions = [];
        $totalSelectableSize = 0;
        $trackersDetected = [];

        foreach ($allMovies as $movie) {
            $item = $this->buildSuggestionItem($movie, $trackerRules, $seedingStatus, $volumeId, $excludeProtected);
            if ($item === null) {
                continue;
            }

            $totalSelectableSize += $item['total_file_size_bytes'];

            // Collect tracker domains
            foreach ($item['files'] as $file) {
                foreach ($file['torrents'] as $torrent) {
                    if ($torrent['tracker_domain'] !== null) {
                        $trackersDetected[$torrent['tracker_domain']] = true;
                    }
                }
            }

            $suggestions[] = $item;
        }

        // Paginate
        $total = count($suggestions);
        $totalPages = max(1, (int)ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;
        $paginatedData = array_slice($suggestions, $offset, $perPage);

        $meta = [
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
            'summary' => [
                'total_selectable_size' => $totalSelectableSize,
                'trackers_detected' => array_keys($trackersDetected),
            ],
        ];

        // Add volume space info if volume_id filter is used
        if ($volumeId !== null) {
            $volume = $this->volumeRepository->find($volumeId);
            if ($volume !== null) {
                $meta['volume_space'] = [
                    'volume_id' => (string)$volume->getId(),
                    'volume_name' => $volume->getName(),
                    'used_space_bytes' => $volume->getUsedSpaceBytes(),
                    'total_space_bytes' => $volume->getTotalSpaceBytes(),
                ];
            }
        }

        return [
            'data' => $paginatedData,
            'meta' => $meta,
        ];
    }

    /**
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

        $movieFiles = $movie->getMovieFiles();
        if ($movieFiles->isEmpty()) {
            return null;
        }

        $filesData = [];
        $totalFileSize = 0;
        $allTorrentStats = [];

        foreach ($movieFiles as $mf) {
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
            $allTorrentStats = array_merge($allTorrentStats, $torrents);

            $fileSeedingStatus = $this->calculateSeedingStatus($torrents);
            $trackerCheck = $this->isBlockedByTrackerRules($torrents, $trackerRules);
            $realFreedBytes = $this->calculateRealFreedBytes($mediaFile);

            $filesData[] = [
                'id' => (string)$mediaFile->getId(),
                'file_name' => $mediaFile->getFileName(),
                'file_path' => $mediaFile->getFilePath(),
                'file_size_bytes' => $mediaFile->getFileSizeBytes(),
                'hardlink_count' => $mediaFile->getHardlinkCount(),
                'real_freed_bytes' => $realFreedBytes,
                'volume_id' => (string)$mediaFile->getVolume()?->getId(),
                'volume_name' => $mediaFile->getVolume()?->getName(),
                'resolution' => $mediaFile->getResolution(),
                'codec' => $mediaFile->getCodec(),
                'is_protected' => $mediaFile->isProtected(),
                'partial_hash' => $mediaFile->getPartialHash(),
                'seeding_status' => $fileSeedingStatus,
                'cross_seed_count' => count($torrents),
                'blocked_by_tracker_rules' => $trackerCheck['blocked'],
                'tracker_block_reason' => $trackerCheck['reason'],
                'torrents' => $torrents,
            ];

            $totalFileSize += $mediaFile->getFileSizeBytes();
        }

        if ($filesData === []) {
            return null;
        }

        // Calculate movie-level seeding status
        $movieSeedingStatus = $this->calculateMovieSeedingStatus($filesData);

        // Filter by seeding_status
        if ($seedingStatus === 'orphans_only' && $movieSeedingStatus !== 'orphan') {
            return null;
        }
        if ($seedingStatus === 'seeding_only' && $movieSeedingStatus !== 'seeding') {
            return null;
        }

        // Calculate aggregate stats
        $bestRatio = null;
        $worstRatio = null;
        $maxSeedTime = 0;
        $totalCrossSeedCount = 0;
        $isBlockedByAnyRule = false;

        foreach ($filesData as $file) {
            $totalCrossSeedCount += $file['cross_seed_count'];
            if ($file['blocked_by_tracker_rules']) {
                $isBlockedByAnyRule = true;
            }
            foreach ($file['torrents'] as $torrent) {
                $ratio = $torrent['ratio'];
                if ($bestRatio === null || $ratio > $bestRatio) {
                    $bestRatio = $ratio;
                }
                if ($worstRatio === null || $ratio < $worstRatio) {
                    $worstRatio = $ratio;
                }
                if ($torrent['seed_time_seconds'] > $maxSeedTime) {
                    $maxSeedTime = $torrent['seed_time_seconds'];
                }
            }
        }

        return [
            'movie' => [
                'id' => (string)$movie->getId(),
                'title' => $movie->getTitle(),
                'year' => $movie->getYear(),
                'poster_url' => $movie->getPosterUrl(),
                'genres' => $movie->getGenres(),
                'rating' => $movie->getRating() !== null ? (float)$movie->getRating() : null,
                'is_protected' => $movie->isProtected(),
            ],
            'files' => $filesData,
            'seeding_status' => $movieSeedingStatus,
            'total_file_size_bytes' => $totalFileSize,
            'best_ratio' => $bestRatio,
            'worst_ratio' => $worstRatio,
            'total_seed_time_max_seconds' => $maxSeedTime,
            'cross_seed_count' => $totalCrossSeedCount,
            'blocked_by_tracker_rules' => $isBlockedByAnyRule,
            'file_count' => count($filesData),
        ];
    }

    /**
     * Get serialized torrent stats for a media file.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getFileTorrents(MediaFile $file): array
    {
        $stats = $this->torrentStatRepository->findByMediaFile($file);
        $torrents = [];

        foreach ($stats as $stat) {
            $torrents[] = $this->serializeTorrentStat($stat);
        }

        return $torrents;
    }

    /**
     * Serialize a single TorrentStat.
     *
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

    /**
     * Calculate seeding status from a file's torrents.
     */
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

    /**
     * Calculate movie-level seeding status from all files.
     */
    private function calculateMovieSeedingStatus(array $filesData): string
    {
        $statuses = array_unique(array_column($filesData, 'seeding_status'));

        if (count($statuses) === 1) {
            return $statuses[0];
        }

        return 'mixed';
    }

    /**
     * Check if any torrent is blocked by tracker rules.
     *
     * @param array<int, array<string, mixed>> $torrents
     * @param array<string, TrackerRule> $trackerRules
     *
     * @return array{blocked: bool, reason: ?string}
     */
    private function isBlockedByTrackerRules(array $torrents, array $trackerRules): array
    {
        foreach ($torrents as $torrent) {
            $domain = $torrent['tracker_domain'] ?? null;
            if ($domain === null || !isset($trackerRules[$domain])) {
                continue;
            }

            $rule = $trackerRules[$domain];
            $ratio = $torrent['ratio'];
            $seedTimeHours = $torrent['seed_time_seconds'] / 3600;

            $minRatio = (float)$rule->getMinRatio();
            $minSeedTimeHours = $rule->getMinSeedTimeHours();

            if ($minRatio > 0 && $ratio < $minRatio) {
                return [
                    'blocked' => true,
                    'reason' => sprintf('Ratio %.4f < minimum %.4f for %s', $ratio, $minRatio, $domain),
                ];
            }

            if ($minSeedTimeHours > 0 && $seedTimeHours < $minSeedTimeHours) {
                return [
                    'blocked' => true,
                    'reason' => sprintf('Seed time %.1fh < minimum %dh for %s', $seedTimeHours, $minSeedTimeHours, $domain),
                ];
            }
        }

        return ['blocked' => false, 'reason' => null];
    }

    /**
     * Calculate real freed bytes considering hardlinks.
     */
    private function calculateRealFreedBytes(MediaFile $file): int
    {
        if ($file->getHardlinkCount() > 1) {
            return 0;
        }

        return $file->getFileSizeBytes();
    }
}
