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

final class DashboardService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MovieRepository $movieRepository,
        private readonly VolumeRepository $volumeRepository,
        private readonly ActivityLogRepository $activityLogRepository,
    ) {
    }

    /** @return array<string, mixed> */
    public function getStats(): array
    {
        $totalMovies = $this->movieRepository->count([]);

        $fileStats = $this->em->createQueryBuilder()
            ->select('COUNT(mf.id) AS total_files, COALESCE(SUM(mf.fileSizeBytes), 0) AS total_size')
            ->from(MediaFile::class, 'mf')
            ->getQuery()
            ->getSingleResult();

        $totalFiles = (int)$fileStats['total_files'];
        $totalSizeBytes = (int)$fileStats['total_size'];

        $orphanFilesCount = (int)$this->em->createQueryBuilder()
            ->select('COUNT(mf.id)')
            ->from(MediaFile::class, 'mf')
            ->leftJoin('mf.movieFiles', 'mof')
            ->where('mof.id IS NULL')
            ->getQuery()
            ->getSingleScalarResult();

        $upcomingDeletionsCount = (int)$this->em->createQueryBuilder()
            ->select('COUNT(sd.id)')
            ->from(ScheduledDeletion::class, 'sd')
            ->where('sd.status IN (:statuses)')
            ->setParameter('statuses', [DeletionStatus::PENDING, DeletionStatus::REMINDER_SENT])
            ->getQuery()
            ->getSingleScalarResult();

        $volumes = $this->volumeRepository->findBy(['status' => VolumeStatus::ACTIVE]);
        $volumeStats = $this->buildVolumeStats($volumes);

        $recentLogs = $this->activityLogRepository->findBy([], ['createdAt' => 'DESC'], 20);
        $recentActivity = array_map(fn (ActivityLog $log): array => [
            'action' => $log->getAction(),
            'entity_type' => $log->getEntityType(),
            'details' => $log->getDetails() ?? [],
            'user' => $log->getUser()?->getUsername() ?? 'system',
            'created_at' => $log->getCreatedAt()->format('c'),
        ], $recentLogs);

        return [
            'total_movies' => $totalMovies,
            'total_files' => $totalFiles,
            'total_size_bytes' => $totalSizeBytes,
            'volumes' => $volumeStats,
            'orphan_files_count' => $orphanFilesCount,
            'upcoming_deletions_count' => $upcomingDeletionsCount,
            'recent_activity' => $recentActivity,
        ];
    }

    /**
     * @param Volume[] $volumes
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildVolumeStats(array $volumes): array
    {
        $volumeFileStats = [];
        if ($volumes !== []) {
            $rows = $this->em->createQueryBuilder()
                ->select('IDENTITY(mf.volume) AS vol_id, COUNT(mf.id) AS file_count, COALESCE(SUM(mf.fileSizeBytes), 0) AS used_space')
                ->from(MediaFile::class, 'mf')
                ->where('mf.volume IN (:volumes)')
                ->setParameter('volumes', $volumes)
                ->groupBy('mf.volume')
                ->getQuery()
                ->getResult();

            foreach ($rows as $row) {
                $volumeFileStats[$row['vol_id']] = [
                    'file_count' => (int)$row['file_count'],
                    'used_space' => (int)$row['used_space'],
                ];
            }
        }

        return array_map(function (Volume $v) use ($volumeFileStats): array {
            $volId = (string)$v->getId();
            $stats = $volumeFileStats[$volId] ?? ['file_count' => 0, 'used_space' => 0];

            return [
                'id' => $volId,
                'name' => $v->getName(),
                'total_space_bytes' => $v->getTotalSpaceBytes() ?? 0,
                'used_space_bytes' => $stats['used_space'],
                'file_count' => $stats['file_count'],
            ];
        }, $volumes);
    }
}
