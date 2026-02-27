<?php

namespace App\Repository;

use App\Entity\TorrentStat;
use App\Entity\TorrentStatHistory;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TorrentStatHistory>
 */
class TorrentStatHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TorrentStatHistory::class);
    }

    /**
     * Find latest history records for a torrent stat.
     *
     * @return TorrentStatHistory[]
     */
    public function findLatestForStat(TorrentStat $stat, int $limit = 30): array
    {
        return $this->createQueryBuilder('tsh')
            ->where('tsh.torrentStat = :stat')
            ->setParameter('stat', $stat)
            ->orderBy('tsh.recordedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Check if a snapshot has already been created today for a given stat.
     */
    public function hasSnapshotToday(TorrentStat $stat): bool
    {
        $today = new DateTimeImmutable('today');
        $tomorrow = new DateTimeImmutable('tomorrow');

        $count = $this->createQueryBuilder('tsh')
            ->select('COUNT(tsh.id)')
            ->where('tsh.torrentStat = :stat')
            ->andWhere('tsh.recordedAt >= :today')
            ->andWhere('tsh.recordedAt < :tomorrow')
            ->setParameter('stat', $stat)
            ->setParameter('today', $today)
            ->setParameter('tomorrow', $tomorrow)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Delete history records older than a specific date.
     */
    public function deleteOlderThan(DateTimeImmutable $date): int
    {
        return $this->createQueryBuilder('tsh')
            ->delete()
            ->where('tsh.recordedAt < :date')
            ->setParameter('date', $date)
            ->getQuery()
            ->execute();
    }
}
