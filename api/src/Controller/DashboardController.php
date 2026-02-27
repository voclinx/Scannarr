<?php

namespace App\Controller;

use App\Entity\ActivityLog;
use App\Entity\MediaFile;
use App\Entity\ScheduledDeletion;
use App\Entity\Volume;
use App\Enum\DeletionStatus;
use App\Enum\VolumeStatus;
use App\Repository\ActivityLogRepository;
use App\Repository\MovieRepository;
use App\Repository\ScheduledDeletionRepository;
use App\Repository\VolumeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1')]
class DashboardController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MovieRepository $movieRepository,
        private readonly VolumeRepository $volumeRepository,
        private readonly ScheduledDeletionRepository $deletionRepository,
        private readonly ActivityLogRepository $activityLogRepository,
    ) {
    }

    #[Route('/dashboard', methods: ['GET'])]
    #[IsGranted('ROLE_GUEST')]
    public function index(): JsonResponse
    {
        // Total movies
        $totalMovies = $this->movieRepository->count([]);

        // Total files and total size
        $fileStats = $this->em->createQueryBuilder()
            ->select('COUNT(mf.id) AS total_files, COALESCE(SUM(mf.fileSizeBytes), 0) AS total_size')
            ->from(MediaFile::class, 'mf')
            ->getQuery()
            ->getSingleResult();

        $totalFiles = (int)$fileStats['total_files'];
        $totalSizeBytes = (int)$fileStats['total_size'];

        // Orphan files (files not linked to any movie)
        $orphanFilesCount = (int)$this->em->createQueryBuilder()
            ->select('COUNT(mf.id)')
            ->from(MediaFile::class, 'mf')
            ->leftJoin('mf.movieFiles', 'mof')
            ->where('mof.id IS NULL')
            ->getQuery()
            ->getSingleScalarResult();

        // Upcoming deletions (pending or reminder_sent)
        $upcomingDeletionsCount = (int)$this->em->createQueryBuilder()
            ->select('COUNT(sd.id)')
            ->from(ScheduledDeletion::class, 'sd')
            ->where('sd.status IN (:statuses)')
            ->setParameter('statuses', [DeletionStatus::PENDING, DeletionStatus::REMINDER_SENT])
            ->getQuery()
            ->getSingleScalarResult();

        // Volumes stats (single grouped query to avoid N+1)
        $volumes = $this->volumeRepository->findBy(['status' => VolumeStatus::ACTIVE]);

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

        $volumeStats = array_map(function (Volume $v) use ($volumeFileStats): array {
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

        // Recent activity (last 20)
        $recentLogs = $this->activityLogRepository->findBy(
            [],
            ['createdAt' => 'DESC'],
            20,
        );

        $recentActivity = array_map(fn (ActivityLog $log): array => [
            'action' => $log->getAction(),
            'entity_type' => $log->getEntityType(),
            'details' => $log->getDetails() ?? [],
            'user' => $log->getUser()?->getUsername() ?? 'system',
            'created_at' => $log->getCreatedAt()->format('c'),
        ], $recentLogs);

        return $this->json([
            'data' => [
                'total_movies' => $totalMovies,
                'total_files' => $totalFiles,
                'total_size_bytes' => $totalSizeBytes,
                'volumes' => $volumeStats,
                'orphan_files_count' => $orphanFilesCount,
                'upcoming_deletions_count' => $upcomingDeletionsCount,
                'recent_activity' => $recentActivity,
            ],
        ]);
    }
}
