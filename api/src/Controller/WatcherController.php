<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\WatcherService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/watchers')]
class WatcherController extends AbstractController
{
    public function __construct(private readonly WatcherService $watcherService) {}

    #[Route('', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function index(): JsonResponse
    {
        return $this->json(['data' => $this->watcherService->list()]);
    }

    #[Route('/{id}', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function show(string $id): JsonResponse
    {
        $data = $this->watcherService->find($id);
        if ($data === null) {
            return $this->json(['error' => ['code' => 404, 'message' => 'Watcher not found']], 404);
        }

        return $this->json(['data' => $data]);
    }

    #[Route('/{id}/approve', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function approve(string $id): JsonResponse
    {
        $result = $this->watcherService->approve($id);

        if ($result['result'] === 'not_found') {
            return $this->json(['error' => ['code' => 404, 'message' => 'Watcher not found']], 404);
        }
        if ($result['result'] === 'revoked') {
            return $this->json(['error' => ['code' => 422, 'message' => 'Cannot approve a revoked watcher']], 422);
        }

        return $this->json(['data' => $result['data']]);
    }

    #[Route('/{id}/config', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function updateConfig(string $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => ['code' => 400, 'message' => 'Invalid JSON']], 400);
        }

        $result = $this->watcherService->updateConfig($id, $data);
        if ($result === null) {
            return $this->json(['error' => ['code' => 404, 'message' => 'Watcher not found']], 404);
        }

        return $this->json(['data' => $result]);
    }

    #[Route('/{id}/name', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function updateName(string $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $name = trim((string) ($data['name'] ?? ''));
        $result = $this->watcherService->updateName($id, $name);

        if ($result['result'] === 'not_found') {
            return $this->json(['error' => ['code' => 404, 'message' => 'Watcher not found']], 404);
        }
        if ($result['result'] === 'empty_name') {
            return $this->json(['error' => ['code' => 422, 'message' => 'Name is required']], 422);
        }

        return $this->json(['data' => $result['data']]);
    }

    #[Route('/{id}/revoke', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function revoke(string $id): JsonResponse
    {
        $data = $this->watcherService->revoke($id);
        if ($data === null) {
            return $this->json(['error' => ['code' => 404, 'message' => 'Watcher not found']], 404);
        }

        return $this->json(['data' => $data]);
    }

    #[Route('/{id}', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(string $id): JsonResponse
    {
        if (!$this->watcherService->delete($id)) {
            return $this->json(['error' => ['code' => 404, 'message' => 'Watcher not found']], 404);
        }

        return $this->json(null, 204);
    }

    #[Route('/{id}/logs', methods: ['GET'])]
    #[IsGranted('ROLE_ADVANCED_USER')]
    public function logs(string $id, Request $request): JsonResponse
    {
        $level = $request->query->get('level');
        $limit = min((int) $request->query->get('limit', 100), 1000);
        $offset = max((int) $request->query->get('offset', 0), 0);

        $result = $this->watcherService->getLogs($id, $level, $limit, $offset);
        if ($result === null) {
            return $this->json(['error' => ['code' => 404, 'message' => 'Watcher not found']], 404);
        }

        return $this->json(['data' => $result['data'], 'meta' => $result['meta']]);
    }

    #[Route('/{id}/debug', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function toggleDebug(string $id): JsonResponse
    {
        $data = $this->watcherService->toggleDebug($id);
        if ($data === null) {
            return $this->json(['error' => ['code' => 404, 'message' => 'Watcher not found']], 404);
        }

        return $this->json(['data' => $data]);
    }
}
