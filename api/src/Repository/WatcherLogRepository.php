<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Watcher;
use App\Entity\WatcherLog;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WatcherLog>
 */
class WatcherLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WatcherLog::class);
    }

    /**
     * Find logs for a given watcher with optional level filter.
     *
     * @return WatcherLog[]
     */
    public function findByWatcher(Watcher $watcher, ?string $level = null, int $limit = 100, int $offset = 0): array
    {
        $qb = $this->createQueryBuilder('l')
            ->where('l.watcher = :watcher')
            ->setParameter('watcher', $watcher)
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults(min($limit, 1000))
            ->setFirstResult($offset);

        if ($level !== null) {
            $qb->andWhere('l.level = :level')
                ->setParameter('level', $level);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Count logs for a given watcher with optional level filter.
     */
    public function countByWatcher(Watcher $watcher, ?string $level = null): int
    {
        $qb = $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.watcher = :watcher')
            ->setParameter('watcher', $watcher);

        if ($level !== null) {
            $qb->andWhere('l.level = :level')
                ->setParameter('level', $level);
        }

        return (int)$qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Delete logs for a watcher that are older than the given date.
     */
    public function deleteOlderThan(Watcher $watcher, DateTimeImmutable $before, ?string $level = null): int
    {
        $qb = $this->createQueryBuilder('l')
            ->delete()
            ->where('l.watcher = :watcher')
            ->andWhere('l.createdAt < :before')
            ->setParameter('watcher', $watcher)
            ->setParameter('before', $before);

        if ($level !== null) {
            $qb->andWhere('l.level = :level')
                ->setParameter('level', $level);
        }

        return (int)$qb->getQuery()->execute();
    }
}
