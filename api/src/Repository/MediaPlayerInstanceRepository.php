<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MediaPlayerInstance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MediaPlayerInstance>
 */
class MediaPlayerInstanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MediaPlayerInstance::class);
    }
}
