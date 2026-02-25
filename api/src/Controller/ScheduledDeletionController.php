<?php

namespace App\Controller;

use App\Entity\ScheduledDeletion;
use App\Entity\ScheduledDeletionItem;
use App\Entity\User;
use App\Enum\DeletionStatus;
use App\Repository\MovieRepository;
use App\Repository\ScheduledDeletionRepository;
use App\Security\Voter\DeletionVoter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/scheduled-deletions')]
class ScheduledDeletionController extends AbstractController
{
    public function __construct(
        private ScheduledDeletionRepository $deletionRepository,
        private MovieRepository $movieRepository,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * GET /api/v1/scheduled-deletions — List scheduled deletions with pagination.
     */
    #[Route('', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function list(Request $request): JsonResponse
    {
        $page = $request->query->getInt('page', 1);
        $limit = $request->query->getInt('limit', 25);
        $status = $request->query->get('status');

        $result = $this->deletionRepository->findWithFilters([
            'page' => $page,
            'limit' => $limit,
            'status' => $status,
        ]);

        $data = array_map(fn(ScheduledDeletion $d) => $this->serializeForList($d), $result['data']);

        return $this->json([
            'data' => $data,
            'meta' => [
                'total' => $result['total'],
                'page' => $result['page'],
                'limit' => $result['limit'],
                'total_pages' => $result['total_pages'],
            ],
        ]);
    }

    /**
     * POST /api/v1/scheduled-deletions — Create a scheduled deletion.
     */
    #[Route('', methods: ['POST'])]
    #[IsGranted('ROLE_ADVANCED_USER')]
    public function create(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        if (!$payload) {
            return $this->json(
                ['error' => ['code' => 400, 'message' => 'Invalid JSON']],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Validate required fields
        $scheduledDate = $payload['scheduled_date'] ?? null;
        $items = $payload['items'] ?? [];

        if (!$scheduledDate) {
            return $this->json(
                ['error' => ['code' => 422, 'message' => 'Validation failed', 'details' => ['scheduled_date' => 'Required']]],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if (empty($items)) {
            return $this->json(
                ['error' => ['code' => 422, 'message' => 'Validation failed', 'details' => ['items' => 'At least one item is required']]],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // Validate date is in the future
        $date = new \DateTime($scheduledDate);
        $today = new \DateTime('today');
        if ($date < $today) {
            return $this->json(
                ['error' => ['code' => 422, 'message' => 'Validation failed', 'details' => ['scheduled_date' => 'Date must be in the future']]],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        /** @var User $user */
        $user = $this->getUser();

        $deletion = new ScheduledDeletion();
        $deletion->setCreatedBy($user);
        $deletion->setScheduledDate($date);
        $deletion->setDeletePhysicalFiles((bool) ($payload['delete_physical_files'] ?? true));
        $deletion->setDeleteRadarrReference((bool) ($payload['delete_radarr_reference'] ?? false));
        $deletion->setDeleteMediaPlayerReference((bool) ($payload['delete_media_player_reference'] ?? false));
        $deletion->setReminderDaysBefore((int) ($payload['reminder_days_before'] ?? 3));

        $totalFilesCount = 0;

        // Create items
        foreach ($items as $itemData) {
            $movieId = $itemData['movie_id'] ?? null;
            $mediaFileIds = $itemData['media_file_ids'] ?? [];

            if (!$movieId) {
                continue;
            }

            $movie = $this->movieRepository->find($movieId);
            if (!$movie) {
                return $this->json(
                    ['error' => ['code' => 422, 'message' => "Movie not found: {$movieId}"]],
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            $item = new ScheduledDeletionItem();
            $item->setMovie($movie);
            $item->setMediaFileIds($mediaFileIds);
            $deletion->addItem($item);
            $totalFilesCount += count($mediaFileIds);
        }

        $this->em->persist($deletion);
        $this->em->flush();

        return $this->json([
            'data' => [
                'id' => (string) $deletion->getId(),
                'scheduled_date' => $deletion->getScheduledDate()->format('Y-m-d'),
                'execution_time' => $deletion->getExecutionTime()->format('H:i:s'),
                'status' => $deletion->getStatus()->value,
                'delete_physical_files' => $deletion->isDeletePhysicalFiles(),
                'delete_radarr_reference' => $deletion->isDeleteRadarrReference(),
                'delete_media_player_reference' => $deletion->isDeleteMediaPlayerReference(),
                'reminder_days_before' => $deletion->getReminderDaysBefore(),
                'items_count' => $deletion->getItems()->count(),
                'total_files_count' => $totalFilesCount,
                'created_by' => $user->getUsername(),
                'created_at' => $deletion->getCreatedAt()->format('c'),
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * GET /api/v1/scheduled-deletions/{id} — Detail with items.
     */
    #[Route('/{id}', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function detail(string $id): JsonResponse
    {
        $deletion = $this->deletionRepository->find($id);

        if (!$deletion) {
            return $this->json(
                ['error' => ['code' => 404, 'message' => 'Scheduled deletion not found']],
                Response::HTTP_NOT_FOUND
            );
        }

        return $this->json(['data' => $this->serializeDetail($deletion)]);
    }

    /**
     * PUT /api/v1/scheduled-deletions/{id} — Modify date, items, options.
     */
    #[Route('/{id}', methods: ['PUT'])]
    #[IsGranted('ROLE_ADVANCED_USER')]
    public function update(string $id, Request $request): JsonResponse
    {
        $deletion = $this->deletionRepository->find($id);

        if (!$deletion) {
            return $this->json(
                ['error' => ['code' => 404, 'message' => 'Scheduled deletion not found']],
                Response::HTTP_NOT_FOUND
            );
        }

        // Can only modify pending or reminder_sent deletions
        if (!in_array($deletion->getStatus(), [DeletionStatus::PENDING, DeletionStatus::REMINDER_SENT], true)) {
            return $this->json(
                ['error' => ['code' => 409, 'message' => 'Cannot modify a deletion with status: ' . $deletion->getStatus()->value]],
                Response::HTTP_CONFLICT
            );
        }

        // Ownership check via Voter
        $this->denyAccessUnlessGranted(DeletionVoter::EDIT, $deletion);

        $payload = json_decode($request->getContent(), true);
        if (!$payload) {
            return $this->json(
                ['error' => ['code' => 400, 'message' => 'Invalid JSON']],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Update date
        if (isset($payload['scheduled_date'])) {
            $date = new \DateTime($payload['scheduled_date']);
            $today = new \DateTime('today');
            if ($date < $today) {
                return $this->json(
                    ['error' => ['code' => 422, 'message' => 'Validation failed', 'details' => ['scheduled_date' => 'Date must be in the future']]],
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }
            $deletion->setScheduledDate($date);
            // Reset status to pending if date changes
            $deletion->setStatus(DeletionStatus::PENDING);
        }

        // Update options
        if (isset($payload['delete_physical_files'])) {
            $deletion->setDeletePhysicalFiles((bool) $payload['delete_physical_files']);
        }
        if (isset($payload['delete_radarr_reference'])) {
            $deletion->setDeleteRadarrReference((bool) $payload['delete_radarr_reference']);
        }
        if (isset($payload['delete_media_player_reference'])) {
            $deletion->setDeleteMediaPlayerReference((bool) $payload['delete_media_player_reference']);
        }
        if (isset($payload['reminder_days_before'])) {
            $deletion->setReminderDaysBefore((int) $payload['reminder_days_before']);
        }

        // Update items if provided
        if (isset($payload['items'])) {
            // Remove existing items
            foreach ($deletion->getItems() as $existingItem) {
                $this->em->remove($existingItem);
            }

            // Add new items
            foreach ($payload['items'] as $itemData) {
                $movieId = $itemData['movie_id'] ?? null;
                $mediaFileIds = $itemData['media_file_ids'] ?? [];

                if (!$movieId) {
                    continue;
                }

                $movie = $this->movieRepository->find($movieId);
                if (!$movie) {
                    return $this->json(
                        ['error' => ['code' => 422, 'message' => "Movie not found: {$movieId}"]],
                        Response::HTTP_UNPROCESSABLE_ENTITY
                    );
                }

                $item = new ScheduledDeletionItem();
                $item->setMovie($movie);
                $item->setMediaFileIds($mediaFileIds);
                $deletion->addItem($item);
            }
        }

        $this->em->flush();

        return $this->json(['data' => $this->serializeDetail($deletion)]);
    }

    /**
     * DELETE /api/v1/scheduled-deletions/{id} — Cancel a scheduled deletion.
     */
    #[Route('/{id}', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADVANCED_USER')]
    public function cancel(string $id): JsonResponse
    {
        $deletion = $this->deletionRepository->find($id);

        if (!$deletion) {
            return $this->json(
                ['error' => ['code' => 404, 'message' => 'Scheduled deletion not found']],
                Response::HTTP_NOT_FOUND
            );
        }

        // Can only cancel pending or reminder_sent deletions
        if (!in_array($deletion->getStatus(), [DeletionStatus::PENDING, DeletionStatus::REMINDER_SENT], true)) {
            return $this->json(
                ['error' => ['code' => 409, 'message' => 'Cannot cancel a deletion with status: ' . $deletion->getStatus()->value]],
                Response::HTTP_CONFLICT
            );
        }

        // Ownership check via Voter
        $this->denyAccessUnlessGranted(DeletionVoter::CANCEL, $deletion);

        $deletion->setStatus(DeletionStatus::CANCELLED);
        $this->em->flush();

        return $this->json([
            'data' => ['message' => 'Scheduled deletion cancelled', 'id' => (string) $deletion->getId()],
        ]);
    }

    /**
     * Serialize a scheduled deletion for the list endpoint.
     *
     * @return array<string, mixed>
     */
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
            'items_count' => $deletion->getItems()->count(),
            'total_files_count' => $totalFiles,
            'created_by' => $deletion->getCreatedBy()?->getUsername(),
            'created_at' => $deletion->getCreatedAt()->format('c'),
            'executed_at' => $deletion->getExecutedAt()?->format('c'),
        ];
    }

    /**
     * Serialize a scheduled deletion with full details and items.
     *
     * @return array<string, mixed>
     */
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
            'reminder_days_before' => $deletion->getReminderDaysBefore(),
            'reminder_sent_at' => $deletion->getReminderSentAt()?->format('c'),
            'executed_at' => $deletion->getExecutedAt()?->format('c'),
            'execution_report' => $deletion->getExecutionReport(),
            'items_count' => count($items),
            'total_files_count' => $totalFiles,
            'items' => $items,
            'created_by' => [
                'id' => (string) $deletion->getCreatedBy()?->getId(),
                'username' => $deletion->getCreatedBy()?->getUsername(),
            ],
            'created_at' => $deletion->getCreatedAt()->format('c'),
            'updated_at' => $deletion->getUpdatedAt()->format('c'),
        ];
    }
}
