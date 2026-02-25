<?php

namespace App\Repository;

use App\Entity\ScheduledDeletion;
use App\Enum\DeletionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
     * @return array{data: ScheduledDeletion[], total: int, page: int, limit: int, total_pages: int}
     */
    public function findWithFilters(array $filters): array
    {
        $page = max(1, $filters['page'] ?? 1);
        $limit = min(100, max(1, $filters['limit'] ?? 25));
        $status = $filters['status'] ?? null;

        $qb = $this->createQueryBuilder('d')
            ->leftJoin('d.createdBy', 'u')
            ->addSelect('u')
            ->leftJoin('d.items', 'i')
            ->addSelect('i');

        if ($status !== null) {
            $statusEnum = DeletionStatus::tryFrom($status);
            if ($statusEnum !== null) {
                $qb->andWhere('d.status = :status')
                    ->setParameter('status', $statusEnum);
            }
        }

        $qb->orderBy('d.scheduledDate', 'ASC')
            ->addOrderBy('d.createdAt', 'DESC');

        // Count total
        $countQb = $this->createQueryBuilder('d')
            ->select('COUNT(d.id)');

        if ($status !== null) {
            $statusEnum = DeletionStatus::tryFrom($status);
            if ($statusEnum !== null) {
                $countQb->andWhere('d.status = :status')
                    ->setParameter('status', $statusEnum);
            }
        }

        $total = (int) $countQb->getQuery()->getSingleScalarResult();

        $offset = ($page - 1) * $limit;
        $qb->setFirstResult($offset)->setMaxResults($limit);

        return [
            'data' => $qb->getQuery()->getResult(),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => (int) ceil($total / $limit),
        ];
    }

    /**
     * Find deletions that are due for execution today.
     *
     * @return ScheduledDeletion[]
     */
    public function findDueForExecution(): array
    {
        $today = new \DateTime('today');

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
        $today = new \DateTime('today');

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
}
