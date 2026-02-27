<?php

namespace App\Controller;

use App\Entity\Movie;
use App\Entity\ScheduledDeletion;
use App\Entity\ScheduledDeletionItem;
use App\Entity\User;
use App\Enum\DeletionStatus;
use App\Repository\MovieRepository;
use App\Service\DeletionService;
use App\Service\SuggestionService;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/suggestions')]
#[IsGranted('ROLE_ADVANCED_USER')]
class SuggestionController extends AbstractController
{
    public function __construct(
        private readonly SuggestionService $suggestionService,
        private readonly DeletionService $deletionService,
        private readonly MovieRepository $movieRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $filters = [
            'seeding_status' => $request->query->get('seeding_status', 'all'),
            'volume_id' => $request->query->get('volume_id'),
            'exclude_protected' => $request->query->getBoolean('exclude_protected', true),
            'page' => $request->query->getInt('page', 1),
            'per_page' => $request->query->getInt('per_page', 50),
        ];

        $result = $this->suggestionService->getSuggestions($filters);

        return $this->json([
            'data' => $result['data'],
            'meta' => $result['meta'],
        ]);
    }

    #[Route('/batch-delete', methods: ['POST'])]
    public function batchDelete(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        if (!$payload) {
            return $this->json(
                ['error' => ['code' => 400, 'message' => 'Invalid JSON']],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $items = $payload['items'] ?? [];
        if (empty($items)) {
            return $this->json(
                ['error' => ['code' => 422, 'message' => 'At least one item is required']],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $options = $payload['options'] ?? [];

        /** @var User $user */
        $user = $this->getUser();

        $deletion = new ScheduledDeletion();
        $deletion->setCreatedBy($user);
        $deletion->setScheduledDate(new DateTime('today'));
        $deletion->setDeletePhysicalFiles(true);
        $deletion->setDeleteRadarrReference((bool)($options['delete_radarr_reference'] ?? false));
        $deletion->setDisableRadarrAutoSearch((bool)($options['disable_radarr_auto_search'] ?? true));

        $itemsCount = 0;
        foreach ($items as $itemData) {
            $movieId = $itemData['movie_id'] ?? null;
            $fileIds = $itemData['file_ids'] ?? [];

            if (!$movieId) {
                continue;
            }

            $movie = $this->movieRepository->find($movieId);
            if (!$movie instanceof Movie) {
                continue;
            }

            $item = new ScheduledDeletionItem();
            $item->setMovie($movie);
            $item->setMediaFileIds($fileIds);
            $deletion->addItem($item);
            ++$itemsCount;
        }

        if ($itemsCount === 0) {
            return $this->json(
                ['error' => ['code' => 422, 'message' => 'No valid items found']],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $this->em->persist($deletion);
        $this->em->flush();

        $this->deletionService->executeDeletion($deletion);

        return $this->json([
            'data' => [
                'deletion_id' => (string)$deletion->getId(),
                'status' => $deletion->getStatus()->value,
                'items_count' => $itemsCount,
            ],
        ], $deletion->getStatus() === DeletionStatus::COMPLETED ? Response::HTTP_OK : Response::HTTP_ACCEPTED);
    }

    #[Route('/batch-schedule', methods: ['POST'])]
    public function batchSchedule(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        if (!$payload) {
            return $this->json(
                ['error' => ['code' => 400, 'message' => 'Invalid JSON']],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $items = $payload['items'] ?? [];
        $scheduledDate = $payload['scheduled_date'] ?? null;

        if (empty($items)) {
            return $this->json(
                ['error' => ['code' => 422, 'message' => 'At least one item is required']],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if (!$scheduledDate) {
            return $this->json(
                ['error' => ['code' => 422, 'message' => 'scheduled_date is required']],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $date = new DateTime($scheduledDate);
        $today = new DateTime('today');
        if ($date < $today) {
            return $this->json(
                ['error' => ['code' => 422, 'message' => 'scheduled_date must be in the future']],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $options = $payload['options'] ?? [];

        /** @var User $user */
        $user = $this->getUser();

        $deletion = new ScheduledDeletion();
        $deletion->setCreatedBy($user);
        $deletion->setScheduledDate($date);
        $deletion->setDeletePhysicalFiles(true);
        $deletion->setDeleteRadarrReference((bool)($options['delete_radarr_reference'] ?? false));
        $deletion->setDisableRadarrAutoSearch((bool)($options['disable_radarr_auto_search'] ?? true));

        $itemsCount = 0;
        foreach ($items as $itemData) {
            $movieId = $itemData['movie_id'] ?? null;
            $fileIds = $itemData['file_ids'] ?? [];

            if (!$movieId) {
                continue;
            }

            $movie = $this->movieRepository->find($movieId);
            if (!$movie instanceof Movie) {
                continue;
            }

            $item = new ScheduledDeletionItem();
            $item->setMovie($movie);
            $item->setMediaFileIds($fileIds);
            $deletion->addItem($item);
            ++$itemsCount;
        }

        if ($itemsCount === 0) {
            return $this->json(
                ['error' => ['code' => 422, 'message' => 'No valid items found']],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $this->em->persist($deletion);
        $this->em->flush();

        return $this->json([
            'data' => [
                'deletion_id' => (string)$deletion->getId(),
                'scheduled_date' => $deletion->getScheduledDate()->format('Y-m-d'),
                'status' => $deletion->getStatus()->value,
                'items_count' => $itemsCount,
            ],
        ], Response::HTTP_CREATED);
    }
}
