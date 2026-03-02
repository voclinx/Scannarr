<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MediaFile;
use App\Entity\TorrentStat;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TorrentStat>
 */
class TorrentStatRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TorrentStat::class);
    }

    /**
     * Find all torrents for a media file.
     *
     * @return TorrentStat[]
     */
    public function findByMediaFile(MediaFile $file): array
    {
        return $this->createQueryBuilder('ts')
            ->where('ts.mediaFile = :file')
            ->setParameter('file', $file)
            ->orderBy('ts.trackerDomain', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find torrent by hash.
     */
    public function findByHash(string $hash): ?TorrentStat
    {
        return $this->createQueryBuilder('ts')
            ->where('ts.torrentHash = :hash')
            ->setParameter('hash', $hash)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find torrents not seen since a specific date.
     *
     * @return TorrentStat[]
     */
    public function findNotSeenSince(DateTimeImmutable $date): array
    {
        return $this->createQueryBuilder('ts')
            ->where('ts.lastSyncedAt < :date')
            ->setParameter('date', $date)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all TorrentStats linked to any media_file sharing the same physical inode.
     * This crosses volume boundaries (Media, Torrents, Cross-seed hardlinks).
     *
     * @return TorrentStat[]
     */
    public function findByInode(string $deviceId, string $inode): array
    {
        return $this->createQueryBuilder('ts')
            ->join('ts.mediaFile', 'mf')
            ->where('mf.deviceId = :deviceId')
            ->andWhere('mf.inode = :inode')
            ->setParameter('deviceId', $deviceId)
            ->setParameter('inode', $inode)
            ->orderBy('ts.trackerDomain', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all torrents for a tracker domain.
     *
     * @return TorrentStat[]
     */
    public function findByTrackerDomain(string $domain): array
    {
        return $this->createQueryBuilder('ts')
            ->where('ts.trackerDomain = :domain')
            ->setParameter('domain', $domain)
            ->orderBy('ts.ratio', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
