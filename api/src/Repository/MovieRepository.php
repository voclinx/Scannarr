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
 */
class MovieRepository extends ServiceEntityRepository
{
    private const array SORT_MAP = [
        'title' => 'm.title',
        'year' => 'm.year',
        'rating' => 'm.rating',
        'runtime_minutes' => 'm.runtimeMinutes',
        'created_at' => 'm.createdAt',
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

        $qb = $this->createQueryBuilder('m');
        $this->applyMovieFilters($qb, $filters);

        $total = (int)(clone $qb)->select('COUNT(m.id)')->getQuery()->getSingleScalarResult();

        $sortField = self::SORT_MAP[$filters['sort'] ?? 'title'] ?? 'm.title';
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
     * Apply search and instance filters to a QueryBuilder.
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
