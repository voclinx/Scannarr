<?php

namespace App\Repository;

use App\Entity\Volume;
use App\Enum\VolumeStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Volume>
 */
class VolumeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Volume::class);
    }

    /**
     * Find a volume whose host_path is a prefix of the given absolute path.
     * Returns the most specific match (longest host_path).
     */
    public function findByHostPathPrefix(string $absolutePath): ?Volume
    {
        $volumes = $this->findBy(['status' => VolumeStatus::ACTIVE]);

        $bestMatch = null;
        $bestLength = 0;

        foreach ($volumes as $volume) {
            $hostPath = rtrim((string)$volume->getHostPath(), '/');
            if (str_starts_with($absolutePath, $hostPath . '/') || $absolutePath === $hostPath) {
                $len = strlen($hostPath);
                if ($len > $bestLength) {
                    $bestMatch = $volume;
                    $bestLength = $len;
                }
            }
        }

        return $bestMatch;
    }

    /**
     * Find all active volumes.
     *
     * @return Volume[]
     */
    public function findAllActive(): array
    {
        return $this->findBy(['status' => VolumeStatus::ACTIVE]);
    }
}
