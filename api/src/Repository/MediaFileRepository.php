<?php

namespace App\Repository;

use App\Entity\MediaFile;
use App\Entity\Volume;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MediaFile>
 */
class MediaFileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MediaFile::class);
    }

    /**
     * Find a media file by its volume and relative file path.
     */
    public function findByVolumeAndFilePath(Volume $volume, string $filePath): ?MediaFile
    {
        return $this->findOneBy([
            'volume' => $volume,
            'filePath' => $filePath,
        ]);
    }

    /**
     * Get all file paths for a given volume (used during scan cleanup).
     *
     * @return string[]
     */
    public function findAllFilePathsByVolume(Volume $volume): array
    {
        $qb = $this->createQueryBuilder('mf')
            ->select('mf.filePath')
            ->where('mf.volume = :volume')
            ->setParameter('volume', $volume);

        $result = $qb->getQuery()->getScalarResult();

        return array_column($result, 'filePath');
    }

    /**
     * Delete media files by volume and file paths.
     *
     * @param string[] $filePaths
     *
     * @return int Number of deleted records
     */
    public function deleteByVolumeAndFilePaths(Volume $volume, array $filePaths): int
    {
        if ($filePaths === []) {
            return 0;
        }

        $qb = $this->createQueryBuilder('mf')
            ->delete()
            ->where('mf.volume = :volume')
            ->andWhere('mf.filePath IN (:paths)')
            ->setParameter('volume', $volume)
            ->setParameter('paths', $filePaths);

        return $qb->getQuery()->execute();
    }

    /**
     * Find all media files with the same file name (across all volumes).
     * Used for global file deletion.
     *
     * @return MediaFile[]
     */
    public function findByFileName(string $fileName): array
    {
        return $this->createQueryBuilder('mf')
            ->leftJoin('mf.volume', 'v')
            ->addSelect('v')
            ->where('mf.fileName = :fileName')
            ->setParameter('fileName', $fileName)
            ->getQuery()
            ->getResult();
    }

    /**
     * Count media files for a volume.
     */
    public function countByVolume(Volume $volume): int
    {
        return $this->count(['volume' => $volume]);
    }

    /**
     * Find media files by exact file size in bytes.
     *
     * @return MediaFile[]
     */
    public function findByFileSizeBytes(int $sizeBytes): array
    {
        return $this->createQueryBuilder('mf')
            ->where('mf.fileSizeBytes = :size')
            ->setParameter('size', $sizeBytes)
            ->getQuery()
            ->getResult();
    }
}
