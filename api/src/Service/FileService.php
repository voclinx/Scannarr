<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ActivityLog;
use App\Entity\MediaFile;
use App\Entity\ScheduledDeletion;
use App\Entity\ScheduledDeletionItem;
use App\Entity\User;
use App\Enum\DeletionStatus;
use App\Repository\MediaFileRepository;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

final readonly class FileService
{
    public function __construct(
        private EntityManagerInterface $em,
        private MediaFileRepository $mediaFileRepository,
        private DeletionService $deletionService,
    ) {
    }

    /**
     * List files with search, filters and pagination.
     *
     * @param array<string, mixed> $filters
     *
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function list(array $filters): array
    {
        $page = max(1, (int)($filters['page'] ?? 1));
        $limit = min(100, max(1, (int)($filters['limit'] ?? 25)));
        $offset = ($page - 1) * $limit;

        $qb = $this->em->createQueryBuilder()
            ->select('mf', 'v')
            ->from(MediaFile::class, 'mf')
            ->leftJoin('mf.volume', 'v');

        $this->applyFileListFilters($qb, $filters);

        $countQb = clone $qb;
        $countQb->select('COUNT(mf.id)');
        $total = (int)$countQb->getQuery()->getSingleScalarResult();

        $this->applySortAndPaginate($qb, $filters, $offset, $limit);
        $files = $qb->getQuery()->getResult();

        return [
            'data' => array_map($this->serializeFile(...), $files),
            'meta' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ];
    }

    /** @param array<string, mixed> $filters */
    private function applyFileListFilters(QueryBuilder $qb, array $filters): void
    {
        if (!empty($filters['volume_id'])) {
            $qb->andWhere('v.id = :volumeId')->setParameter('volumeId', $filters['volume_id']);
        }

        if (!empty($filters['search'])) {
            $qb->andWhere('LOWER(mf.fileName) LIKE :search OR LOWER(mf.filePath) LIKE :search')
                ->setParameter('search', '%' . strtolower((string)$filters['search']) . '%');
        }

        $isLinkedRadarr = $filters['is_linked_radarr'] ?? null;
        if ($isLinkedRadarr !== null && $isLinkedRadarr !== '') {
            $qb->andWhere('mf.isLinkedRadarr = :linkedRadarr')
                ->setParameter('linkedRadarr', filter_var($isLinkedRadarr, FILTER_VALIDATE_BOOLEAN));
        }

        $minHardlinks = $filters['min_hardlinks'] ?? null;
        if ($minHardlinks !== null && $minHardlinks !== '') {
            $qb->andWhere('mf.hardlinkCount >= :minHardlinks')->setParameter('minHardlinks', (int)$minHardlinks);
        }
    }

    /** @param array<string, mixed> $filters */
    private function applySortAndPaginate(QueryBuilder $qb, array $filters, int $offset, int $limit): void
    {
        $allowedSorts = ['file_name', 'file_size_bytes', 'detected_at', 'hardlink_count', 'file_path'];
        $sortField = $filters['sort'] ?? 'detected_at';
        $sortOrder = strtoupper((string)($filters['order'] ?? 'DESC'));

        if (!in_array($sortField, $allowedSorts, true)) {
            $sortField = 'detected_at';
        }
        if (!in_array($sortOrder, ['ASC', 'DESC'], true)) {
            $sortOrder = 'DESC';
        }

        $sortMap = [
            'file_name' => 'mf.fileName',
            'file_size_bytes' => 'mf.fileSizeBytes',
            'detected_at' => 'mf.detectedAt',
            'hardlink_count' => 'mf.hardlinkCount',
            'file_path' => 'mf.filePath',
        ];

        $qb->orderBy($sortMap[$sortField], $sortOrder)->setFirstResult($offset)->setMaxResults($limit);
    }

    public function findById(string $id): ?MediaFile
    {
        return $this->mediaFileRepository->find($id);
    }

    /**
     * Get all MediaFiles sharing the same physical inode as $file (siblings), excluding $file itself.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function getSiblings(MediaFile $file): array
    {
        $siblings = $this->mediaFileRepository->findSiblingsByInode($file);

        return [
            'data' => array_map(fn (MediaFile $file): array => [
                'id' => (string)$file->getId(),
                'file_path' => $file->getFilePath(),
                'file_name' => $file->getFileName(),
                'file_size_bytes' => $file->getFileSizeBytes(),
                'volume_id' => (string)$file->getVolume()?->getId(),
                'volume_name' => $file->getVolume()?->getName(),
            ], $siblings),
            'meta' => [
                'inode' => $file->getInode(),
                'device_id' => $file->getDeviceId(),
                'hardlink_count_on_disk' => $file->getHardlinkCount(),
                'known_in_scanarr' => count($siblings) + 1,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function serializeFile(MediaFile $file): array
    {
        return [
            'id' => (string)$file->getId(),
            'volume_id' => (string)$file->getVolume()->getId(),
            'volume_name' => $file->getVolume()->getName(),
            'file_path' => $file->getFilePath(),
            'file_name' => $file->getFileName(),
            'file_size_bytes' => $file->getFileSizeBytes(),
            'hardlink_count' => $file->getHardlinkCount(),
            'inode' => $file->getInode(),
            'device_id' => $file->getDeviceId(),
            'resolution' => $file->getResolution(),
            'codec' => $file->getCodec(),
            'quality' => $file->getQuality(),
            'is_linked_radarr' => $file->isLinkedRadarr(),
            'is_linked_media_player' => $file->isLinkedMediaPlayer(),
            'detected_at' => $file->getDetectedAt()->format('c'),
            'updated_at' => $file->getUpdatedAt()->format('c'),
        ];
    }

    /**
     * Delete a single file (ephemeral deletion pipeline).
     *
     * @return array{deletion_id: string, status: string, http_code: int}
     */
    public function deleteFile(MediaFile $file, bool $deletePhysical, bool $deleteRadarrRef, bool $disableRadarrAutoSearch, User $user): array
    {
        $deletion = $this->buildSingleFileDeletion($file, $deletePhysical, $deleteRadarrRef, $disableRadarrAutoSearch, $user);

        $this->em->persist($deletion);
        $this->em->flush();

        $this->deletionService->executeDeletion($deletion);

        $this->logFileActivity($file, $user, 'file.deleted', [
            'file_name' => $file->getFileName(),
            'file_path' => $file->getFilePath(),
            'volume' => $file->getVolume()?->getName(),
            'deletion_id' => (string)$deletion->getId(),
            'status' => $deletion->getStatus()->value,
        ]);

        return [
            'deletion_id' => (string)$deletion->getId(),
            'status' => $deletion->getStatus()->value,
            'http_code' => $this->resolveHttpCode($deletion->getStatus()),
        ];
    }

    private function buildSingleFileDeletion(MediaFile $file, bool $deletePhysical, bool $deleteRadarrRef, bool $disableRadarrAutoSearch, User $user): ScheduledDeletion
    {
        $movie = null;
        foreach ($file->getMovieFiles() as $mf) {
            if ($mf->getMovie() !== null) {
                $movie = $mf->getMovie();
                break;
            }
        }

        $deletion = new ScheduledDeletion();
        $deletion->setCreatedBy($user);
        $deletion->setScheduledDate(new DateTime('today'));
        $deletion->setDeletePhysicalFiles($deletePhysical);
        $deletion->setDeleteRadarrReference($deleteRadarrRef);
        $deletion->setDisableRadarrAutoSearch($disableRadarrAutoSearch);

        $item = new ScheduledDeletionItem();
        $item->setMovie($movie);
        $item->setMediaFileIds([(string)$file->getId()]);
        $deletion->addItem($item);

        return $deletion;
    }

    /**
     * Global delete — removes a file across ALL volumes by file name.
     *
     * @return array{deletion_id: string, status: string, files_count: int, warning?: string, http_code: int}
     */
    public function globalDeleteFile(MediaFile $sourceFile, bool $deletePhysical, bool $deleteRadarrRef, bool $disableRadarrAutoSearch, User $user): array
    {
        $allFiles = $this->mediaFileRepository->findByFileName($sourceFile->getFileName());
        [$movieFiles, $warning] = $this->groupFilesByMovie($allFiles, $deleteRadarrRef, $disableRadarrAutoSearch);

        $deletion = $this->buildGlobalFileDeletion($movieFiles, $deletePhysical, $deleteRadarrRef, $disableRadarrAutoSearch, $user);
        $this->em->persist($deletion);
        $this->em->flush();

        $this->deletionService->executeDeletion($deletion);
        $this->logAllFileDeletions($allFiles, $sourceFile, $deletion, $user);
        $this->em->flush();

        $result = [
            'deletion_id' => (string)$deletion->getId(),
            'status' => $deletion->getStatus()->value,
            'files_count' => count($allFiles),
            'http_code' => $this->resolveHttpCode($deletion->getStatus()),
        ];

        if ($warning !== null) {
            $result['warning'] = $warning;
        }

        return $result;
    }

    /**
     * @param MediaFile[] $allFiles
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     *
     * @return array{0: array<string, array{movie: mixed, file_ids: string[]}>, 1: string|null}
     */
    private function groupFilesByMovie(array $allFiles, bool $deleteRadarrRef, bool $disableRadarrAutoSearch): array
    {
        $movieFiles = [];
        $warning = null;

        foreach ($allFiles as $file) {
            $movie = null;
            foreach ($file->getMovieFiles() as $mf) {
                if ($mf->getMovie() !== null) {
                    $movie = $mf->getMovie();
                    break;
                }
            }

            $movieId = $movie !== null ? (string)$movie->getId() : '__no_movie__';
            if (!isset($movieFiles[$movieId])) {
                $movieFiles[$movieId] = ['movie' => $movie, 'file_ids' => []];
            }
            $movieFiles[$movieId]['file_ids'][] = (string)$file->getId();

            if ($movie !== null && !$disableRadarrAutoSearch && !$deleteRadarrRef && $movie->isRadarrMonitored()) {
                $warning = 'Radarr auto-search is still enabled for this movie. It may be re-downloaded.';
            }
        }

        return [$movieFiles, $warning];
    }

    /**
     * @param array<string, array{movie: mixed, file_ids: string[]}> $movieFiles
     */
    private function buildGlobalFileDeletion(array $movieFiles, bool $deletePhysical, bool $deleteRadarrRef, bool $disableRadarrAutoSearch, User $user): ScheduledDeletion
    {
        $deletion = new ScheduledDeletion();
        $deletion->setCreatedBy($user);
        $deletion->setScheduledDate(new DateTime('today'));
        $deletion->setDeletePhysicalFiles($deletePhysical);
        $deletion->setDeleteRadarrReference($deleteRadarrRef);
        $deletion->setDisableRadarrAutoSearch($disableRadarrAutoSearch);

        foreach ($movieFiles as $movieData) {
            $item = new ScheduledDeletionItem();
            $item->setMovie($movieData['movie']);
            $item->setMediaFileIds($movieData['file_ids']);
            $deletion->addItem($item);
        }

        return $deletion;
    }

    /**
     * @param MediaFile[] $allFiles
     */
    private function logAllFileDeletions(array $allFiles, MediaFile $sourceFile, ScheduledDeletion $deletion, User $user): void
    {
        foreach ($allFiles as $file) {
            $this->logFileActivity($file, $user, 'file.global_deleted', [
                'file_name' => $file->getFileName(),
                'file_path' => $file->getFilePath(),
                'volume' => $file->getVolume()?->getName(),
                'global_source_id' => (string)$sourceFile->getId(),
                'deletion_id' => (string)$deletion->getId(),
            ]);
        }
    }

    /** @param array<string, mixed> $details */
    private function logFileActivity(MediaFile $file, User $user, string $action, array $details): void
    {
        $log = new ActivityLog();
        $log->setAction($action);
        $log->setEntityType('MediaFile');
        $log->setEntityId($file->getId());
        $log->setUser($user);
        $log->setDetails($details);
        $this->em->persist($log);
    }

    private function resolveHttpCode(DeletionStatus $status): int
    {
        return match ($status) {
            DeletionStatus::EXECUTING, DeletionStatus::WAITING_WATCHER => 202,
            default => 200,
        };
    }
}
