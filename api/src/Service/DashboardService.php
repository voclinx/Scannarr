<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ActivityLog;
use App\Entity\MediaFile;
use App\Entity\ScheduledDeletion;
use App\Entity\Volume;
use App\Enum\DeletionStatus;
use App\Enum\VolumeStatus;
use App\Repository\ActivityLogRepository;
use App\Repository\MovieRepository;
use App\Repository\VolumeRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DashboardService
{
    public function __construct(
        private EntityManagerInterface $em,
        private MovieRepository $movieRepository,
        private VolumeRepository $volumeRepository,
        private ActivityLogRepository $activityLogRepository,
    ) {
    }

    /** @return array<string, mixed> */
    public function getStats(): array
    {
        $fileStats = $this->queryFileStats();
        $volumes = $this->volumeRepository->findBy(['status' => VolumeStatus::ACTIVE]);

        return [
            'total_movies' => $this->movieRepository->count([]),
            'total_files' => $fileStats['total_files'],
            'total_size_bytes' => $fileStats['total_size'],
            'volumes' => $this->buildVolumeStats($volumes),
            'orphan_files_count' => $this->countOrphanFiles(),
            'upcoming_deletions_count' => $this->countUpcomingDeletions(),
            'recent_activity' => $this->buildRecentActivity(),
        ];
    }

    /**
     * @param Volume[] $volumes
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildVolumeStats(array $volumes): array
    {
        $volumeFileStats = $this->queryVolumeFileStats($volumes);

        return array_map(function (Volume $volume) use ($volumeFileStats): array {
            $volId = (string)$volume->getId();
            $stats = $volumeFileStats[$volId] ?? ['file_count' => 0, 'used_space' => 0];

            return [
                'id' => $volId,
                'name' => $volume->getName(),
                'total_space_bytes' => $volume->getTotalSpaceBytes() ?? 0,
                'used_space_bytes' => $stats['used_space'],
                'file_count' => $stats['file_count'],
            ];
        }, $volumes);
    }

    /** @return array{total_files: int, total_size: int} */
    private function queryFileStats(): array
    {
        $result = $this->em->createQueryBuilder()
            ->select('COUNT(mf.id) AS total_files, COALESCE(SUM(mf.fileSizeBytes), 0) AS total_size')
            ->from(MediaFile::class, 'mf')
            ->getQuery()
            ->getSingleResult();

        return [
            'total_files' => (int)$result['total_files'],
            'total_size' => (int)$result['total_size'],
        ];
    }

    private function countOrphanFiles(): int
    {
        return (int)$this->em->createQueryBuilder()
            ->select('COUNT(mf.id)')
            ->from(MediaFile::class, 'mf')
            ->leftJoin('mf.movieFiles', 'mof')
            ->where('mof.id IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function countUpcomingDeletions(): int
    {
        return (int)$this->em->createQueryBuilder()
            ->select('COUNT(sd.id)')
            ->from(ScheduledDeletion::class, 'sd')
            ->where('sd.status IN (:statuses)')
            ->setParameter('statuses', [DeletionStatus::PENDING, DeletionStatus::REMINDER_SENT])
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return array<int, array<string, mixed>> */
    private function buildRecentActivity(): array
    {
        $recentLogs = $this->activityLogRepository->findBy([], ['createdAt' => 'DESC'], 20);

        return array_map(fn (ActivityLog $log): array => [
            'action' => $log->getAction(),
            'entity_type' => $log->getEntityType(),
            'details' => $log->getDetails() ?? [],
            'user' => $log->getUser()?->getUsername() ?? 'system',
            'created_at' => $log->getCreatedAt()->format('c'),
        ], $recentLogs);
    }

    /**
     * @param Volume[] $volumes
     *
     * @return array<string, array{file_count: int, used_space: int}>
     */
    private function queryVolumeFileStats(array $volumes): array
    {
        if ($volumes === []) {
            return [];
        }

        $rows = $this->em->createQueryBuilder()
            ->select('IDENTITY(mf.volume) AS vol_id, COUNT(mf.id) AS file_count, COALESCE(SUM(mf.fileSizeBytes), 0) AS used_space')
            ->from(MediaFile::class, 'mf')
            ->where('mf.volume IN (:volumes)')
            ->setParameter('volumes', $volumes)
            ->groupBy('mf.volume')
            ->getQuery()
            ->getResult();

        $stats = [];
        foreach ($rows as $row) {
            $stats[$row['vol_id']] = [
                'file_count' => (int)$row['file_count'],
                'used_space' => (int)$row['used_space'],
            ];
        }

        return $stats;
    }
}
