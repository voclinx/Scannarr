<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Movie;
use App\Entity\ScheduledDeletion;
use App\Entity\ScheduledDeletionItem;
use App\Entity\User;
use App\Enum\DeletionStatus;
use App\Repository\MovieRepository;
use App\Repository\ScheduledDeletionRepository;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class ScheduledDeletionService
{
    public function __construct(
        private readonly ScheduledDeletionRepository $deletionRepository,
        private readonly MovieRepository $movieRepository,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function list(int $page, int $limit, ?string $status): array
    {
        $result = $this->deletionRepository->findWithFilters(['page' => $page, 'limit' => $limit, 'status' => $status]);

        return [
            'data' => array_map($this->serializeForList(...), $result['data']),
            'meta' => [
                'total' => $result['total'],
                'page' => $result['page'],
                'limit' => $result['limit'],
                'total_pages' => $result['total_pages'],
            ],
        ];
    }

    public function find(string $id): ?ScheduledDeletion
    {
        return $this->deletionRepository->find($id);
    }

    /** @return array<string, mixed>|null */
    public function getDetail(string $id): ?array
    {
        $deletion = $this->deletionRepository->find($id);

        return $deletion instanceof ScheduledDeletion ? $this->serializeDetail($deletion) : null;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{result: string, data?: array<string, mixed>, error?: string}
     */
    public function create(array $data, User $user): array
    {
        $scheduledDate = $data['scheduled_date'] ?? null;
        $items = $data['items'] ?? [];

        if (!$scheduledDate) {
            return ['result' => 'validation_error', 'field' => 'scheduled_date', 'error' => 'Required'];
        }
        if ($items === []) {
            return ['result' => 'validation_error', 'field' => 'items', 'error' => 'At least one item is required'];
        }

        $date = new DateTime((string) $scheduledDate);
        $today = new DateTime('today');
        if ($date < $today) {
            return ['result' => 'validation_error', 'field' => 'scheduled_date', 'error' => 'Date must be in the future'];
        }

        $deletion = new ScheduledDeletion();
        $deletion->setCreatedBy($user);
        $deletion->setScheduledDate($date);
        $deletion->setDeletePhysicalFiles((bool) ($data['delete_physical_files'] ?? true));
        $deletion->setDeleteRadarrReference((bool) ($data['delete_radarr_reference'] ?? false));
        $deletion->setDeleteMediaPlayerReference((bool) ($data['delete_media_player_reference'] ?? false));
        $deletion->setDisableRadarrAutoSearch((bool) ($data['disable_radarr_auto_search'] ?? false));
        $deletion->setReminderDaysBefore((int) ($data['reminder_days_before'] ?? 3));

        $totalFilesCount = 0;
        foreach ($items as $itemData) {
            $movieId = $itemData['movie_id'] ?? null;
            if (!$movieId) {
                continue;
            }

            $movie = $this->movieRepository->find($movieId);
            if (!$movie instanceof Movie) {
                return ['result' => 'movie_not_found', 'error' => "Movie not found: {$movieId}"];
            }

            $mediaFileIds = $itemData['media_file_ids'] ?? [];
            $item = new ScheduledDeletionItem();
            $item->setMovie($movie);
            $item->setMediaFileIds($mediaFileIds);
            $deletion->addItem($item);
            $totalFilesCount += count($mediaFileIds);
        }

        $this->em->persist($deletion);
        $this->em->flush();

        return [
            'result' => 'created',
            'data' => [
                'id' => (string) $deletion->getId(),
                'scheduled_date' => $deletion->getScheduledDate()->format('Y-m-d'),
                'execution_time' => $deletion->getExecutionTime()->format('H:i:s'),
                'status' => $deletion->getStatus()->value,
                'delete_physical_files' => $deletion->isDeletePhysicalFiles(),
                'delete_radarr_reference' => $deletion->isDeleteRadarrReference(),
                'delete_media_player_reference' => $deletion->isDeleteMediaPlayerReference(),
                'disable_radarr_auto_search' => $deletion->isDisableRadarrAutoSearch(),
                'reminder_days_before' => $deletion->getReminderDaysBefore(),
                'items_count' => $deletion->getItems()->count(),
                'total_files_count' => $totalFilesCount,
                'created_by' => $user->getUsername(),
                'created_at' => $deletion->getCreatedAt()->format('c'),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{result: string, data?: array<string, mixed>, error?: string}
     */
    public function update(string $id, array $data): array
    {
        $deletion = $this->deletionRepository->find($id);
        if (!$deletion instanceof ScheduledDeletion) {
            return ['result' => 'not_found'];
        }

        if (!in_array($deletion->getStatus(), [DeletionStatus::PENDING, DeletionStatus::REMINDER_SENT], true)) {
            return ['result' => 'invalid_status', 'error' => 'Cannot modify a deletion with status: ' . $deletion->getStatus()->value];
        }

        if (isset($data['scheduled_date'])) {
            $date = new DateTime((string) $data['scheduled_date']);
            $today = new DateTime('today');
            if ($date < $today) {
                return ['result' => 'validation_error', 'error' => 'Date must be in the future'];
            }
            $deletion->setScheduledDate($date);
            $deletion->setStatus(DeletionStatus::PENDING);
        }

        if (isset($data['delete_physical_files'])) {
            $deletion->setDeletePhysicalFiles((bool) $data['delete_physical_files']);
        }
        if (isset($data['delete_radarr_reference'])) {
            $deletion->setDeleteRadarrReference((bool) $data['delete_radarr_reference']);
        }
        if (isset($data['delete_media_player_reference'])) {
            $deletion->setDeleteMediaPlayerReference((bool) $data['delete_media_player_reference']);
        }
        if (isset($data['disable_radarr_auto_search'])) {
            $deletion->setDisableRadarrAutoSearch((bool) $data['disable_radarr_auto_search']);
        }
        if (isset($data['reminder_days_before'])) {
            $deletion->setReminderDaysBefore((int) $data['reminder_days_before']);
        }

        if (isset($data['items'])) {
            foreach ($deletion->getItems() as $existingItem) {
                $this->em->remove($existingItem);
            }

            foreach ($data['items'] as $itemData) {
                $movieId = $itemData['movie_id'] ?? null;
                if (!$movieId) {
                    continue;
                }
                $movie = $this->movieRepository->find($movieId);
                if (!$movie instanceof Movie) {
                    return ['result' => 'movie_not_found', 'error' => "Movie not found: {$movieId}"];
                }
                $item = new ScheduledDeletionItem();
                $item->setMovie($movie);
                $item->setMediaFileIds($itemData['media_file_ids'] ?? []);
                $deletion->addItem($item);
            }
        }

        $this->em->flush();

        return ['result' => 'updated', 'data' => $this->serializeDetail($deletion)];
    }

    /** @return array<string, mixed> */
    public function cancel(ScheduledDeletion $deletion): array
    {
        $deletion->setStatus(DeletionStatus::CANCELLED);
        $this->em->flush();

        return ['message' => 'Scheduled deletion cancelled', 'id' => (string) $deletion->getId()];
    }

    /** @return array<string, mixed> */
    private function serializeForList(ScheduledDeletion $deletion): array
    {
        $totalFiles = 0;
        foreach ($deletion->getItems() as $item) {
            $totalFiles += count($item->getMediaFileIds());
        }

        return [
            'id' => (string) $deletion->getId(),
            'scheduled_date' => $deletion->getScheduledDate()?->format('Y-m-d'),
            'execution_time' => $deletion->getExecutionTime()->format('H:i:s'),
            'status' => $deletion->getStatus()->value,
            'delete_physical_files' => $deletion->isDeletePhysicalFiles(),
            'delete_radarr_reference' => $deletion->isDeleteRadarrReference(),
            'delete_media_player_reference' => $deletion->isDeleteMediaPlayerReference(),
            'disable_radarr_auto_search' => $deletion->isDisableRadarrAutoSearch(),
            'items_count' => $deletion->getItems()->count(),
            'total_files_count' => $totalFiles,
            'created_by' => $deletion->getCreatedBy()?->getUsername(),
            'created_at' => $deletion->getCreatedAt()->format('c'),
            'executed_at' => $deletion->getExecutedAt()?->format('c'),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeDetail(ScheduledDeletion $deletion): array
    {
        $items = [];
        $totalFiles = 0;

        foreach ($deletion->getItems() as $item) {
            $movie = $item->getMovie();
            $totalFiles += count($item->getMediaFileIds());

            $items[] = [
                'id' => (string) $item->getId(),
                'movie' => $movie !== null ? [
                    'id' => (string) $movie->getId(),
                    'title' => $movie->getTitle(),
                    'year' => $movie->getYear(),
                    'poster_url' => $movie->getPosterUrl(),
                ] : null,
                'media_file_ids' => $item->getMediaFileIds(),
                'status' => $item->getStatus(),
                'error_message' => $item->getErrorMessage(),
            ];
        }

        return [
            'id' => (string) $deletion->getId(),
            'scheduled_date' => $deletion->getScheduledDate()?->format('Y-m-d'),
            'execution_time' => $deletion->getExecutionTime()->format('H:i:s'),
            'status' => $deletion->getStatus()->value,
            'delete_physical_files' => $deletion->isDeletePhysicalFiles(),
            'delete_radarr_reference' => $deletion->isDeleteRadarrReference(),
            'delete_media_player_reference' => $deletion->isDeleteMediaPlayerReference(),
            'disable_radarr_auto_search' => $deletion->isDisableRadarrAutoSearch(),
            'reminder_days_before' => $deletion->getReminderDaysBefore(),
            'reminder_sent_at' => $deletion->getReminderSentAt()?->format('c'),
            'executed_at' => $deletion->getExecutedAt()?->format('c'),
            'execution_report' => $deletion->getExecutionReport(),
            'items_count' => count($items),
            'total_files_count' => $totalFiles,
            'items' => $items,
            'created_by' => $deletion->getCreatedBy()?->getUsername(),
            'created_at' => $deletion->getCreatedAt()->format('c'),
            'updated_at' => $deletion->getUpdatedAt()->format('c'),
        ];
    }
}
