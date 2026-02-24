<?php

namespace App\Repository;

use App\Entity\ScheduledDeletionItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ScheduledDeletionItem>
 */
class ScheduledDeletionItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScheduledDeletionItem::class);
    }
}
