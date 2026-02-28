<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\UserService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/users')]
class UserController extends AbstractController
{
    public function __construct(private readonly UserService $userService) {}

    #[Route('', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 25)));
        $result = $this->userService->list($page, $limit);

        return $this->json(['data' => $result['data'], 'meta' => $result['meta']]);
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return $this->json(['error' => ['code' => 400, 'message' => 'Invalid JSON']], 400);
        }

        $result = $this->userService->create($data);

        if ($result['result'] === 'validation_error') {
            return $this->json(['error' => ['code' => 422, 'message' => 'Validation failed', 'details' => $result['details']]], 422);
        }
        if ($result['result'] === 'duplicate') {
            return $this->json(['error' => ['code' => 409, 'message' => 'A user with this email or username already exists']], 409);
        }

        return $this->json(['data' => $result['data']], 201);
    }

    #[Route('/{id}', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return $this->json(['error' => ['code' => 400, 'message' => 'Invalid JSON']], 400);
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $result = $this->userService->update($id, $data, $currentUser);

        if ($result['result'] === 'not_found') {
            return $this->json(['error' => ['code' => 404, 'message' => 'User not found']], 404);
        }
        if ($result['result'] === 'validation_error') {
            return $this->json(['error' => ['code' => 422, 'message' => 'Validation failed', 'details' => $result['details']]], 422);
        }
        if ($result['result'] === 'duplicate') {
            return $this->json(['error' => ['code' => 409, 'message' => 'A user with this email or username already exists']], 409);
        }

        return $this->json(['data' => $result['data']]);
    }

    #[Route('/{id}', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $result = $this->userService->delete($id, $currentUser);

        if ($result === 'not_found') {
            return $this->json(['error' => ['code' => 404, 'message' => 'User not found']], 404);
        }
        if ($result === 'self') {
            return $this->json(['error' => ['code' => 400, 'message' => 'Cannot delete your own account']], 400);
        }

        return $this->json(null, 204);
    }
}
