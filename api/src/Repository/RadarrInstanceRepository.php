<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RadarrInstance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RadarrInstance>
 */
class RadarrInstanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RadarrInstance::class);
    }
}
