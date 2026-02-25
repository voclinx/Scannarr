<?php

namespace App\Controller;

use App\Entity\Volume;
use App\Enum\DeletionStatus;
use App\Enum\VolumeStatus;
use App\Repository\ActivityLogRepository;
use App\Repository\MediaFileRepository;
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
        private EntityManagerInterface $em,
        private MovieRepository $movieRepository,
        private MediaFileRepository $mediaFileRepository,
        private VolumeRepository $volumeRepository,
        private ScheduledDeletionRepository $deletionRepository,
        private ActivityLogRepository $activityLogRepository,
    ) {}

    #[Route('/dashboard', methods: ['GET'])]
    #[IsGranted('ROLE_GUEST')]
    public function index(): JsonResponse
    {
        // Total movies
        $totalMovies = $this->movieRepository->count([]);

        // Total files and total size
        $fileStats = $this->em->createQueryBuilder()
            ->select('COUNT(mf.id) AS total_files, COALESCE(SUM(mf.fileSizeBytes), 0) AS total_size')
            ->from('App\Entity\MediaFile', 'mf')
            ->getQuery()
            ->getSingleResult();

        $totalFiles = (int) $fileStats['total_files'];
        $totalSizeBytes = (int) $fileStats['total_size'];

        // Orphan files (files not linked to any movie)
        $orphanFilesCount = (int) $this->em->createQueryBuilder()
            ->select('COUNT(mf.id)')
            ->from('App\Entity\MediaFile', 'mf')
            ->leftJoin('mf.movieFiles', 'mof')
            ->where('mof.id IS NULL')
            ->getQuery()
            ->getSingleScalarResult();

        // Upcoming deletions (pending or reminder_sent)
        $upcomingDeletionsCount = (int) $this->em->createQueryBuilder()
            ->select('COUNT(sd.id)')
            ->from('App\Entity\ScheduledDeletion', 'sd')
            ->where('sd.status IN (:statuses)')
            ->setParameter('statuses', [DeletionStatus::PENDING, DeletionStatus::REMINDER_SENT])
            ->getQuery()
            ->getSingleScalarResult();

        // Volumes stats
        $volumes = $this->volumeRepository->findBy(['status' => VolumeStatus::ACTIVE]);
        $volumeStats = array_map(function (Volume $v) {
            $fileCount = $this->mediaFileRepository->countByVolume($v);
            $usedSpace = (int) $this->em->createQueryBuilder()
                ->select('COALESCE(SUM(mf.fileSizeBytes), 0)')
                ->from('App\Entity\MediaFile', 'mf')
                ->where('mf.volume = :vol')
                ->setParameter('vol', $v)
                ->getQuery()
                ->getSingleScalarResult();

            return [
                'id' => (string) $v->getId(),
                'name' => $v->getName(),
                'total_space_bytes' => $v->getTotalSpaceBytes() ?? 0,
                'used_space_bytes' => $usedSpace,
                'file_count' => $fileCount,
            ];
        }, $volumes);

        // Recent activity (last 20)
        $recentLogs = $this->activityLogRepository->findBy(
            [],
            ['createdAt' => 'DESC'],
            20
        );

        $recentActivity = array_map(function ($log) {
            return [
                'action' => $log->getAction(),
                'entity_type' => $log->getEntityType(),
                'details' => $log->getDetails() ?? [],
                'user' => $log->getUser()?->getUsername() ?? 'system',
                'created_at' => $log->getCreatedAt()->format('c'),
            ];
        }, $recentLogs);

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
