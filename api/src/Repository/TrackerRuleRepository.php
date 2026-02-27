<?php

namespace App\Repository;

use App\Entity\TrackerRule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrackerRule>
 */
class TrackerRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrackerRule::class);
    }

    /**
     * Find tracker rule by domain.
     */
    public function findByDomain(string $domain): ?TrackerRule
    {
        return $this->createQueryBuilder('tr')
            ->where('tr.trackerDomain = :domain')
            ->setParameter('domain', $domain)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find all tracker rules ordered by domain.
     *
     * @return TrackerRule[]
     */
    public function findAllOrderedByDomain(): array
    {
        return $this->createQueryBuilder('tr')
            ->orderBy('tr.trackerDomain', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
