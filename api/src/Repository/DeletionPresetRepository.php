<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DeletionPreset;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DeletionPreset>
 */
class DeletionPresetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeletionPreset::class);
    }

    /**
     * Find the default preset.
     */
    public function findDefault(): ?DeletionPreset
    {
        return $this->createQueryBuilder('dp')
            ->where('dp.isDefault = true')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find all presets ordered by name.
     *
     * @return DeletionPreset[]
     */
    public function findAllOrderedByName(): array
    {
        return $this->createQueryBuilder('dp')
            ->orderBy('dp.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find system presets only.
     *
     * @return DeletionPreset[]
     */
    public function findSystemPresets(): array
    {
        return $this->createQueryBuilder('dp')
            ->where('dp.isSystem = true')
            ->orderBy('dp.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
