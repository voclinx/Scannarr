<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ScheduledDeletion;
use App\Enum\DeletionStatus;
use DateTime;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ScheduledDeletion>
 */
class ScheduledDeletionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScheduledDeletion::class);
    }

    /**
     * Find scheduled deletions with filters and pagination.
     *
     * @param array{page?: int, limit?: int, status?: string|null} $filters
     *
     * @return array{data: ScheduledDeletion[], total: int, page: int, limit: int, total_pages: int}
     */
    public function findWithFilters(array $filters): array
    {
        $page = max(1, $filters['page'] ?? 1);
        $limit = min(100, max(1, $filters['limit'] ?? 25));

        $qb = $this->createDeletionQueryBuilder();
        $this->applyStatusFilter($qb, $filters['status'] ?? null);

        $qb->orderBy('d.scheduledDate', 'ASC')
            ->addOrderBy('d.createdAt', 'DESC');

        $total = $this->countWithStatusFilter($filters['status'] ?? null);

        $qb->setFirstResult(($page - 1) * $limit)
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
     * Find deletions that are due for execution today.
     *
     * @return ScheduledDeletion[]
     */
    public function findDueForExecution(): array
    {
        $today = new DateTime('today');

        return $this->createQueryBuilder('d')
            ->leftJoin('d.items', 'i')
            ->addSelect('i')
            ->leftJoin('i.movie', 'm')
            ->addSelect('m')
            ->where('d.scheduledDate <= :today')
            ->andWhere('d.status IN (:statuses)')
            ->setParameter('today', $today)
            ->setParameter('statuses', [DeletionStatus::PENDING, DeletionStatus::REMINDER_SENT])
            ->getQuery()
            ->getResult();
    }

    /**
     * Find deletions that need reminder notifications.
     *
     * @return ScheduledDeletion[]
     */
    public function findNeedingReminder(): array
    {
        return $this->createQueryBuilder('d')
            ->leftJoin('d.items', 'i')
            ->addSelect('i')
            ->leftJoin('i.movie', 'm')
            ->addSelect('m')
            ->leftJoin('d.createdBy', 'u')
            ->addSelect('u')
            ->where('d.status = :status')
            ->andWhere('d.reminderSentAt IS NULL')
            ->andWhere('d.reminderDaysBefore IS NOT NULL')
            ->setParameter('status', DeletionStatus::PENDING)
            ->getQuery()
            ->getResult();
    }

    /**
     * Create a QueryBuilder with eager-loaded relations for deletion listing.
     */
    private function createDeletionQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('d')
            ->leftJoin('d.createdBy', 'u')
            ->addSelect('u')
            ->leftJoin('d.items', 'i')
            ->addSelect('i');
    }

    /**
     * Apply status filter to a QueryBuilder.
     */
    private function applyStatusFilter(QueryBuilder $qb, ?string $status): void
    {
        if ($status === null) {
            return;
        }

        $statusEnum = DeletionStatus::tryFrom($status);
        if ($statusEnum !== null) {
            $qb->andWhere('d.status = :status')
                ->setParameter('status', $statusEnum);
        }
    }

    /**
     * Count total deletions matching the given status filter.
     */
    private function countWithStatusFilter(?string $status): int
    {
        $countQb = $this->createQueryBuilder('d')
            ->select('COUNT(d.id)');

        $this->applyStatusFilter($countQb, $status);

        return (int)$countQb->getQuery()->getSingleScalarResult();
    }
}
