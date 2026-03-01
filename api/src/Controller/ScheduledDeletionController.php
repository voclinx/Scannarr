<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ScheduledDeletion;
use App\Entity\User;
use App\Enum\DeletionStatus;
use App\Security\Voter\DeletionVoter;
use App\Service\ScheduledDeletionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/scheduled-deletions')]
class ScheduledDeletionController extends AbstractController
{
    public function __construct(private readonly ScheduledDeletionService $scheduledDeletionService)
    {
    }

    #[Route('', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function list(Request $request): JsonResponse
    {
        $result = $this->scheduledDeletionService->list(
            $request->query->getInt('page', 1),
            $request->query->getInt('limit', 25),
            $request->query->get('status'),
        );

        return $this->json(['data' => $result['data'], 'meta' => $result['meta']]);
    }

    #[Route('', methods: ['POST'])]
    #[IsGranted('ROLE_ADVANCED_USER')]
    public function create(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!$payload) {
            return $this->json(['error' => ['code' => 400, 'message' => 'Invalid JSON']], Response::HTTP_BAD_REQUEST);
        }

        /** @var User $user */
        $user = $this->getUser();
        $result = $this->scheduledDeletionService->create($payload, $user);

        if ($result['result'] === 'validation_error') {
            return $this->json(['error' => ['code' => 422, 'message' => 'Validation failed', 'details' => [$result['field'] => $result['error']]]], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($result['result'] === 'movie_not_found') {
            return $this->json(['error' => ['code' => 422, 'message' => $result['error']]], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['data' => $result['data']], Response::HTTP_CREATED);
    }

    #[Route('/{id}', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function detail(string $id): JsonResponse
    {
        $data = $this->scheduledDeletionService->getDetail($id);
        if ($data === null) {
            return $this->json(['error' => ['code' => 404, 'message' => 'Scheduled deletion not found']], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['data' => $data]);
    }

    #[Route('/{id}', methods: ['PUT'])]
    #[IsGranted('ROLE_ADVANCED_USER')]
    public function update(string $id, Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!$payload) {
            return $this->json(['error' => ['code' => 400, 'message' => 'Invalid JSON']], Response::HTTP_BAD_REQUEST);
        }

        $deletion = $this->scheduledDeletionService->find($id);
        if (!$deletion instanceof ScheduledDeletion) {
            return $this->json(['error' => ['code' => 404, 'message' => 'Scheduled deletion not found']], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted(DeletionVoter::EDIT, $deletion);

        $result = $this->scheduledDeletionService->update($id, $payload);

        if ($result['result'] === 'invalid_status') {
            return $this->json(['error' => ['code' => 409, 'message' => $result['error']]], Response::HTTP_CONFLICT);
        }
        if ($result['result'] === 'validation_error') {
            return $this->json(['error' => ['code' => 422, 'message' => 'Validation failed', 'details' => ['scheduled_date' => $result['error']]]], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($result['result'] === 'movie_not_found') {
            return $this->json(['error' => ['code' => 422, 'message' => $result['error']]], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['data' => $result['data']]);
    }

    #[Route('/{id}', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADVANCED_USER')]
    public function cancel(string $id): JsonResponse
    {
        $deletion = $this->scheduledDeletionService->find($id);
        if (!$deletion instanceof ScheduledDeletion) {
            return $this->json(['error' => ['code' => 404, 'message' => 'Scheduled deletion not found']], Response::HTTP_NOT_FOUND);
        }

        if (!in_array($deletion->getStatus(), [DeletionStatus::PENDING, DeletionStatus::REMINDER_SENT], true)) {
            return $this->json(['error' => ['code' => 409, 'message' => 'Cannot cancel a deletion with status: ' . $deletion->getStatus()->value]], Response::HTTP_CONFLICT);
        }

        $this->denyAccessUnlessGranted(DeletionVoter::CANCEL, $deletion);

        return $this->json(['data' => $this->scheduledDeletionService->cancel($deletion)]);
    }
}
