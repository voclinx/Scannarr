<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\SuggestionService;
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
    public function __construct(private readonly SuggestionService $suggestionService)
    {
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

        return $this->json(['data' => $result['data'], 'meta' => $result['meta']]);
    }

    #[Route('/batch-delete', methods: ['POST'])]
    public function batchDelete(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!$payload) {
            return $this->json(['error' => ['code' => 400, 'message' => 'Invalid JSON']], Response::HTTP_BAD_REQUEST);
        }

        $items = $payload['items'] ?? [];
        if ($items === []) {
            return $this->json(['error' => ['code' => 422, 'message' => 'At least one item is required']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var User $user */
        $user = $this->getUser();
        $result = $this->suggestionService->batchDelete($items, $payload['options'] ?? [], $user);

        if ($result['result'] === 'no_valid_items') {
            return $this->json(['error' => ['code' => 422, 'message' => 'No valid items found']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $httpCode = $result['status'] === 'completed' ? Response::HTTP_OK : Response::HTTP_ACCEPTED;

        return $this->json(['data' => ['deletion_id' => $result['deletion_id'], 'status' => $result['status'], 'items_count' => $result['items_count']]], $httpCode);
    }

    #[Route('/batch-schedule', methods: ['POST'])]
    public function batchSchedule(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!$payload) {
            return $this->json(['error' => ['code' => 400, 'message' => 'Invalid JSON']], Response::HTTP_BAD_REQUEST);
        }

        $items = $payload['items'] ?? [];
        $scheduledDate = $payload['scheduled_date'] ?? null;

        if ($items === []) {
            return $this->json(['error' => ['code' => 422, 'message' => 'At least one item is required']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!$scheduledDate) {
            return $this->json(['error' => ['code' => 422, 'message' => 'scheduled_date is required']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var User $user */
        $user = $this->getUser();
        $result = $this->suggestionService->batchSchedule((string)$scheduledDate, $items, $payload['options'] ?? [], $user);

        if ($result['result'] === 'past_date') {
            return $this->json(['error' => ['code' => 422, 'message' => 'scheduled_date must be in the future']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($result['result'] === 'no_valid_items') {
            return $this->json(['error' => ['code' => 422, 'message' => 'No valid items found']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['data' => ['deletion_id' => $result['deletion_id'], 'scheduled_date' => $result['scheduled_date'], 'status' => $result['status'], 'items_count' => $result['items_count']]], Response::HTTP_CREATED);
    }
}
