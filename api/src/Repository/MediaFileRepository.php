<?php

declare(strict_types=1);

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
     * Find a MediaFile by its (device_id, inode) couple.
     * Returns null if not found or if either value is null/empty.
     */
    public function findByInode(string $deviceId, string $inode): ?MediaFile
    {
        return $this->createQueryBuilder('mf')
            ->where('mf.deviceId = :deviceId')
            ->andWhere('mf.inode = :inode')
            ->setParameter('deviceId', $deviceId)
            ->setParameter('inode', $inode)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find ALL MediaFiles sharing the same (device_id, inode) — i.e. all hardlinks of the same physical file.
     *
     * @return MediaFile[]
     */
    public function findAllByInode(string $deviceId, string $inode): array
    {
        return $this->createQueryBuilder('mf')
            ->where('mf.deviceId = :deviceId')
            ->andWhere('mf.inode = :inode')
            ->setParameter('deviceId', $deviceId)
            ->setParameter('inode', $inode)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all other MediaFiles sharing the same physical inode (excluding self).
     *
     * @return list<MediaFile>
     */
    public function findSiblingsByInode(MediaFile $file): array
    {
        $deviceId = $file->getDeviceId();
        $inode = $file->getInode();

        if ($deviceId === null || $inode === null) {
            return [];
        }

        return $this->createQueryBuilder('mf')
            ->where('mf.deviceId = :deviceId')
            ->andWhere('mf.inode = :inode')
            ->andWhere('mf.id != :selfId')
            ->setParameter('deviceId', $deviceId)
            ->setParameter('inode', $inode)
            ->setParameter('selfId', $file->getId())
            ->getQuery()
            ->getResult();
    }

    /**
     * Find media files whose filePath ends with the given suffix.
     * Used by PathSuffixMatchingStrategy for cross-service path matching.
     *
     * @return MediaFile[]
     */
    public function findByFilePathEndsWith(string $suffix): array
    {
        return $this->createQueryBuilder('mf')
            ->where('mf.filePath LIKE :suffix')
            ->setParameter('suffix', '%' . $suffix)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find media files whose filePath is under the given directory suffix.
     * Uses LIKE '%suffix/%' to match files inside a directory path.
     * Used for matching qBittorrent directory content_paths to MediaFiles.
     *
     * @return MediaFile[]
     */
    public function findByFilePathUnderDirectory(string $directorySuffix): array
    {
        $suffix = rtrim($directorySuffix, '/');

        return $this->createQueryBuilder('mf')
            ->where('mf.filePath LIKE :pattern')
            ->setParameter('pattern', '%' . $suffix . '/%')
            ->getQuery()
            ->getResult();
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

    /**
     * Find all MediaFiles that are NOT linked to any Movie (no MovieFile junction).
     *
     * Includes files with or without TorrentStats — used to surface orphan files
     * in the suggestions page (files in qBit/Plex but not identified as a movie).
     *
     * @return MediaFile[]
     */
    public function findOrphansWithoutMovie(?string $volumeId, bool $excludeProtected): array
    {
        $qb = $this->createQueryBuilder('mf')
            ->leftJoin('mf.movieFiles', 'mvf')
            ->leftJoin('mf.volume', 'v')
            ->addSelect('v')
            ->where('mvf.id IS NULL');

        if ($volumeId !== null) {
            $qb->andWhere('mf.volume = :volumeId')
                ->setParameter('volumeId', $volumeId);
        }

        if ($excludeProtected) {
            $qb->andWhere('mf.isProtected = false');
        }

        return $qb->getQuery()->getResult();
    }
}
