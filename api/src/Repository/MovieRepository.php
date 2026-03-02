<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Movie;
use App\Entity\RadarrInstance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Movie>
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class MovieRepository extends ServiceEntityRepository
{
    private const array SORT_MAP = [
        'title' => 'm.title',
        'year' => 'm.year',
        'rating' => 'm.rating',
        'runtime_minutes' => 'm.runtimeMinutes',
        'created_at' => 'm.createdAt',
        'max_file_size_bytes' => 'maxFileSize',
        'best_ratio' => 'bestRatio',
        'total_seed_time_max_seconds' => 'maxSeedTime',
    ];

    /** Sort keys that require a computed subquery addSelect. */
    private const array COMPUTED_SORT_KEYS = [
        'max_file_size_bytes',
        'best_ratio',
        'total_seed_time_max_seconds',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Movie::class);
    }

    /**
     * Find a movie by its Radarr ID and instance.
     */
    public function findByRadarrIdAndInstance(int $radarrId, RadarrInstance $instance): ?Movie
    {
        return $this->findOneBy(['radarrId' => $radarrId, 'radarrInstance' => $instance]);
    }

    /**
     * Find movies with filters, search, sort, and pagination.
     *
     * @param array<string, mixed> $filters
     *
     * @return array{data: Movie[], total: int, page: int, limit: int, total_pages: int}
     */
    public function findWithFilters(array $filters): array
    {
        $page = max(1, (int)($filters['page'] ?? 1));
        $limit = min(100, max(1, (int)($filters['limit'] ?? 25)));
        $sortKey = $filters['sort'] ?? 'title';

        $qb = $this->createQueryBuilder('m');
        $this->applyMovieFilters($qb, $filters);

        $total = (int)(clone $qb)->select('COUNT(m.id)')->getQuery()->getSingleScalarResult();

        if (in_array($sortKey, self::COMPUTED_SORT_KEYS, true)) {
            $this->addComputedSortSelect($qb, $sortKey);
        }

        $sortField = self::SORT_MAP[$sortKey] ?? 'm.title';
        $order = $this->sanitizeOrder($filters['order'] ?? 'ASC');

        $qb->orderBy($sortField, $order)
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        return [
            'data' => $qb->getQuery()->getResult(),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => (int)ceil($total / $limit),
        ];
    }

    /**
     * Apply all filters to the QueryBuilder.
     *
     * @param array<string, mixed> $filters
     */
    private function applyMovieFilters(QueryBuilder $qb, array $filters): void
    {
        if (!empty($filters['search'])) {
            $qb->andWhere('LOWER(m.title) LIKE LOWER(:search) OR LOWER(m.originalTitle) LIKE LOWER(:search)')
                ->setParameter('search', '%' . $filters['search'] . '%');
        }

        if (!empty($filters['radarr_instance_id'])) {
            $qb->andWhere('m.radarrInstance = :instanceId')
                ->setParameter('instanceId', $filters['radarr_instance_id']);
        }

        $this->applyBooleanFilter($qb, $filters, 'radarr_monitored', 'm.radarrMonitored');
        $this->applyBooleanFilter($qb, $filters, 'is_protected', 'm.isProtected');
        $this->applyPresenceFilters($qb, $filters);
        $this->applySeedingStatusFilter($qb, $filters);
        $this->applyFileCountFilter($qb, $filters);
    }

    /**
     * Apply a simple boolean filter on a direct entity field.
     *
     * @param array<string, mixed> $filters
     */
    private function applyBooleanFilter(QueryBuilder $qb, array $filters, string $key, string $field): void
    {
        if (!isset($filters[$key]) || $filters[$key] === '') {
            return;
        }

        $value = filter_var($filters[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($value === null) {
            return;
        }

        $param = str_replace('.', '_', $key);
        $qb->andWhere("{$field} = :{$param}")
            ->setParameter($param, $value);
    }

    /**
     * Apply presence filters: in_qbit, in_media_player, has_files.
     *
     * @param array<string, mixed> $filters
     */
    private function applyPresenceFilters(QueryBuilder $qb, array $filters): void
    {
        $this->applyExistsFilter($qb, $filters, 'in_qbit', $this->buildInQbitDql());
        $this->applyExistsFilter($qb, $filters, 'in_media_player', $this->buildInMediaPlayerDql());
        $this->applyExistsFilter($qb, $filters, 'has_files', 'EXISTS (SELECT 1 FROM App\Entity\MovieFile mvf_hf WHERE mvf_hf.movie = m)');
    }

    /**
     * Apply an EXISTS / NOT EXISTS filter based on a boolean query param.
     *
     * @param array<string, mixed> $filters
     */
    private function applyExistsFilter(QueryBuilder $qb, array $filters, string $key, string $existsDql): void
    {
        if (!isset($filters[$key]) || $filters[$key] === '') {
            return;
        }

        $value = filter_var($filters[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($value === null) {
            return;
        }

        $qb->andWhere($value ? $existsDql : 'NOT ' . $existsDql);
    }

    /**
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     *
     * Apply seeding_status filter (comma-separated: orphan,seeding,inactive).
     *
     * @param array<string, mixed> $filters
     */
    private function applySeedingStatusFilter(QueryBuilder $qb, array $filters): void
    {
        if (empty($filters['seeding_status'])) {
            return;
        }

        $statuses = array_filter(explode(',', (string)$filters['seeding_status']));
        if ($statuses === []) {
            return;
        }

        $hasTorrentDql = $this->buildInQbitDql();
        $hasActiveDql = $this->buildHasActiveTorrentDql();

        $conditions = [];

        foreach ($statuses as $status) {
            match ($status) {
                'orphan' => $conditions[] = 'NOT ' . $hasTorrentDql,
                'seeding' => $conditions[] = $hasActiveDql,
                'inactive' => $conditions[] = '(' . $hasTorrentDql . ' AND NOT ' . $hasActiveDql . ')',
                default => null,
            };
        }

        if ($conditions !== []) {
            $qb->andWhere('(' . implode(' OR ', $conditions) . ')');
        }
    }

    /**
     * Apply file count min/max filter.
     *
     * @param array<string, mixed> $filters
     */
    private function applyFileCountFilter(QueryBuilder $qb, array $filters): void
    {
        $countDql = '(SELECT COUNT(mvf_fc.id) FROM App\Entity\MovieFile mvf_fc WHERE mvf_fc.movie = m)';

        if (!empty($filters['file_count_min'])) {
            $qb->andWhere("{$countDql} >= :fileCountMin")
                ->setParameter('fileCountMin', (int)$filters['file_count_min']);
        }

        if (!empty($filters['file_count_max'])) {
            $qb->andWhere("{$countDql} <= :fileCountMax")
                ->setParameter('fileCountMax', (int)$filters['file_count_max']);
        }
    }

    /**
     * Add computed subquery SELECT for sorting on derived columns.
     */
    private function addComputedSortSelect(QueryBuilder $qb, string $sortKey): void
    {
        match ($sortKey) {
            'max_file_size_bytes' => $qb->addSelect(
                '(SELECT MAX(mf_sz.fileSizeBytes) FROM App\Entity\MovieFile mvf_sz'
                . ' JOIN mvf_sz.mediaFile mf_sz WHERE mvf_sz.movie = m) AS HIDDEN maxFileSize',
            ),
            'best_ratio' => $qb->addSelect(
                '(SELECT MAX(ts_r.ratio) FROM App\Entity\TorrentStat ts_r'
                . ' JOIN ts_r.mediaFile tmf_r WHERE EXISTS ('
                . 'SELECT 1 FROM App\Entity\MovieFile mvf_r JOIN mvf_r.mediaFile mf_r'
                . ' WHERE mvf_r.movie = m AND mf_r.deviceId = tmf_r.deviceId AND mf_r.inode = tmf_r.inode'
                . ')) AS HIDDEN bestRatio',
            ),
            'total_seed_time_max_seconds' => $qb->addSelect(
                '(SELECT MAX(ts_t.seedTimeSeconds) FROM App\Entity\TorrentStat ts_t'
                . ' JOIN ts_t.mediaFile tmf_t WHERE EXISTS ('
                . 'SELECT 1 FROM App\Entity\MovieFile mvf_t JOIN mvf_t.mediaFile mf_t'
                . ' WHERE mvf_t.movie = m AND mf_t.deviceId = tmf_t.deviceId AND mf_t.inode = tmf_t.inode'
                . ')) AS HIDDEN maxSeedTime',
            ),
            default => null,
        };
    }

    /**
     * DQL: movie has at least one torrent via inode cross-volume lookup.
     */
    private function buildInQbitDql(): string
    {
        return 'EXISTS (SELECT 1 FROM App\Entity\MovieFile mvf_q'
            . ' JOIN mvf_q.mediaFile mf_q WHERE mvf_q.movie = m AND EXISTS ('
            . 'SELECT 1 FROM App\Entity\TorrentStat ts_q JOIN ts_q.mediaFile tmf_q'
            . ' WHERE tmf_q.deviceId = mf_q.deviceId AND tmf_q.inode = mf_q.inode))';
    }

    /**
     * DQL: movie has at least one torrent in SEEDING or STALLED status.
     */
    private function buildHasActiveTorrentDql(): string
    {
        return "EXISTS (SELECT 1 FROM App\Entity\MovieFile mvf_sa"
            . ' JOIN mvf_sa.mediaFile mf_sa WHERE mvf_sa.movie = m AND EXISTS ('
            . "SELECT 1 FROM App\Entity\TorrentStat ts_sa JOIN ts_sa.mediaFile tmf_sa"
            . ' WHERE tmf_sa.deviceId = mf_sa.deviceId AND tmf_sa.inode = mf_sa.inode'
            . " AND ts_sa.status IN ('seeding', 'stalled')))";
    }

    /**
     * DQL: movie has at least one file linked to a media player.
     */
    private function buildInMediaPlayerDql(): string
    {
        return 'EXISTS (SELECT 1 FROM App\Entity\MovieFile mvf_mp'
            . ' JOIN mvf_mp.mediaFile mf_mp WHERE mvf_mp.movie = m AND mf_mp.isLinkedMediaPlayer = true)';
    }

    /**
     * Sanitize sort order to ASC or DESC.
     */
    private function sanitizeOrder(string $order): string
    {
        $order = strtoupper($order);

        return in_array($order, ['ASC', 'DESC'], true) ? $order : 'ASC';
    }
}
