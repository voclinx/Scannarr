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

final class SuggestionService
{
    public function __construct(
        private readonly MovieRepository $movieRepository,
        private readonly TorrentStatRepository $torrentStatRepository,
        private readonly TrackerRuleRepository $trackerRuleRepository,
        private readonly VolumeRepository $volumeRepository,
        private readonly EntityManagerInterface $em,
        private readonly DeletionService $deletionService,
        private readonly MediaFileRepository $mediaFileRepository,
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

            foreach ($item['files'] as $file) {
                foreach ($file['torrents'] as $torrent) {
                    if ($torrent['tracker_domain'] !== null) {
                        $trackersDetected[$torrent['tracker_domain']] = true;
                    }
                }
            }

            $suggestions[] = $item;
        }

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

        return ['data' => $paginatedData, 'meta' => $meta];
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
        $today = new DateTime('today');
        if ($date < $today) {
            return ['result' => 'past_date'];
        }

        $deletion = new ScheduledDeletion();
        $deletion->setCreatedBy($user);
        $deletion->setScheduledDate($date);
        $deletion->setDeletePhysicalFiles(true);
        $deletion->setDeleteRadarrReference((bool)($options['delete_radarr_reference'] ?? false));
        $deletion->setDisableRadarrAutoSearch((bool)($options['disable_radarr_auto_search'] ?? true));

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
            if (!$movieId) {
                continue;
            }

            $movie = $this->movieRepository->find($movieId);
            if (!$movie instanceof Movie) {
                continue;
            }

            $item = new ScheduledDeletionItem();
            $item->setMovie($movie);
            $item->setMediaFileIds($itemData['file_ids'] ?? []);
            $deletion->addItem($item);
            ++$count;
        }

        return $count;
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
            $fileSeedingStatus = $this->calculateSeedingStatus($torrents);
            $trackerCheck = $this->isBlockedByTrackerRules($torrents, $trackerRules);
            $realFreedBytes = $this->calculateRealFreedBytes($mediaFile);

            $filesData[] = [
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

        $movieSeedingStatus = $this->calculateMovieSeedingStatus($filesData);

        if ($seedingStatus === 'orphans_only' && $movieSeedingStatus !== 'orphan') {
            return null;
        }
        if ($seedingStatus === 'seeding_only' && $movieSeedingStatus !== 'seeding') {
            return null;
        }

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

    /** @return array<int, array<string, mixed>> */
    private function getFileTorrents(MediaFile $file): array
    {
        return array_map($this->serializeTorrentStat(...), $this->torrentStatRepository->findByMediaFile($file));
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
            if ($domain === null || !isset($trackerRules[$domain])) {
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
