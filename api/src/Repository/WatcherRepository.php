<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Watcher;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Watcher>
 */
class WatcherRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Watcher::class);
    }

    /**
     * Find a watcher by its watcher_id (the stable ID sent by the watcher).
     */
    public function findByWatcherId(string $watcherId): ?Watcher
    {
        return $this->findOneBy(['watcherId' => $watcherId]);
    }

    /**
     * Find a watcher by its auth token.
     */
    public function findByAuthToken(string $authToken): ?Watcher
    {
        return $this->findOneBy(['authToken' => $authToken]);
    }

    /**
     * Find all watchers ordered by name.
     *
     * @return Watcher[]
     */
    public function findAllOrderedByName(): array
    {
        return $this->findBy([], ['name' => 'ASC']);
    }
}
