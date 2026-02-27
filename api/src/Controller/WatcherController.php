<?php

namespace App\Controller;

use App\Entity\Watcher;
use App\Entity\WatcherLog;
use App\Enum\WatcherStatus;
use App\Repository\WatcherLogRepository;
use App\Repository\WatcherRepository;
use App\Service\WatcherCommandService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/watchers')]
class WatcherController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WatcherRepository $watcherRepository,
        private readonly WatcherLogRepository $watcherLogRepository,
        private readonly WatcherCommandService $watcherCommandService,
    ) {
    }

    /**
     * List all watchers — Admin only.
     */
    #[Route('', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function index(): JsonResponse
    {
        $watchers = $this->watcherRepository->findAllOrderedByName();

        return $this->json(['data' => array_map($this->serializeWatcher(...), $watchers)]);
    }

    /**
     * Get a single watcher — Admin only.
     */
    #[Route('/{id}', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function show(string $id): JsonResponse
    {
        $watcher = $this->watcherRepository->find($id);
        if (!$watcher instanceof Watcher) {
            return $this->json(['error' => ['code' => 404, 'message' => 'Watcher not found']], 404);
        }

        return $this->json(['data' => $this->serializeWatcher($watcher)]);
    }

    /**
     * Approve a pending watcher — Admin only.
     * Generates an auth token and sends config.
     */
    #[Route('/{id}/approve', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function approve(string $id): JsonResponse
    {
        $watcher = $this->watcherRepository->find($id);
        if (!$watcher instanceof Watcher) {
            return $this->json(['error' => ['code' => 404, 'message' => 'Watcher not found']], 404);
        }

        if ($watcher->getStatus() === WatcherStatus::REVOKED) {
            return $this->json(['error' => ['code' => 422, 'message' => 'Cannot approve a revoked watcher']], 422);
        }

        $token = bin2hex(random_bytes(32));
        $watcher->setAuthToken($token);
        $watcher->setStatus(WatcherStatus::APPROVED);
        $this->em->flush();

        // Send config to the watcher if it is currently connected (PENDING state)
        $this->watcherCommandService->sendConfig($watcher);

        return $this->json(['data' => $this->serializeWatcher($watcher)]);
    }

    /**
     * Update a watcher's config — Admin only.
     * Merges the provided fields into the current config (does not replace).
     */
    #[Route('/{id}/config', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function updateConfig(string $id, Request $request): JsonResponse
    {
        $watcher = $this->watcherRepository->find($id);
        if (!$watcher instanceof Watcher) {
            return $this->json(['error' => ['code' => 404, 'message' => 'Watcher not found']], 404);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => ['code' => 400, 'message' => 'Invalid JSON']], 400);
        }

        $watcher->mergeConfig($data);
        $this->em->flush();

        // Push updated config to connected watcher
        $this->watcherCommandService->sendConfig($watcher);

        return $this->json(['data' => $this->serializeWatcher($watcher)]);
    }

    /**
     * Rename a watcher — Admin only.
     */
    #[Route('/{id}/name', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function updateName(string $id, Request $request): JsonResponse
    {
        $watcher = $this->watcherRepository->find($id);
        if (!$watcher instanceof Watcher) {
            return $this->json(['error' => ['code' => 404, 'message' => 'Watcher not found']], 404);
        }

        $data = json_decode($request->getContent(), true);
        $name = trim((string)($data['name'] ?? ''));

        if ($name === '') {
            return $this->json(['error' => ['code' => 422, 'message' => 'Name is required']], 422);
        }

        $watcher->setName($name);
        $this->em->flush();

        return $this->json(['data' => $this->serializeWatcher($watcher)]);
    }

    /**
     * Revoke a watcher — Admin only.
     * Removes the auth token and disconnects the watcher.
     */
    #[Route('/{id}/revoke', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function revoke(string $id): JsonResponse
    {
        $watcher = $this->watcherRepository->find($id);
        if (!$watcher instanceof Watcher) {
            return $this->json(['error' => ['code' => 404, 'message' => 'Watcher not found']], 404);
        }

        $watcher->setStatus(WatcherStatus::REVOKED);
        $watcher->setAuthToken(null);
        $this->em->flush();

        return $this->json(['data' => $this->serializeWatcher($watcher)]);
    }

    /**
     * Delete a watcher — Admin only.
     */
    #[Route('/{id}', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(string $id): JsonResponse
    {
        $watcher = $this->watcherRepository->find($id);
        if (!$watcher instanceof Watcher) {
            return $this->json(['error' => ['code' => 404, 'message' => 'Watcher not found']], 404);
        }

        $this->em->remove($watcher);
        $this->em->flush();

        return $this->json(null, 204);
    }

    /**
     * Get logs for a watcher — AdvancedUser+.
     */
    #[Route('/{id}/logs', methods: ['GET'])]
    #[IsGranted('ROLE_ADVANCED_USER')]
    public function logs(string $id, Request $request): JsonResponse
    {
        $watcher = $this->watcherRepository->find($id);
        if (!$watcher instanceof Watcher) {
            return $this->json(['error' => ['code' => 404, 'message' => 'Watcher not found']], 404);
        }

        $level = $request->query->get('level');
        $limit = min((int)$request->query->get('limit', 100), 1000);
        $offset = max((int)$request->query->get('offset', 0), 0);

        $logs = $this->watcherLogRepository->findByWatcher($watcher, $level !== '' ? $level : null, $limit, $offset);
        $total = $this->watcherLogRepository->countByWatcher($watcher, $level !== '' ? $level : null);

        return $this->json([
            'data' => array_map($this->serializeLog(...), $logs),
            'meta' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
            ],
        ]);
    }

    /**
     * Toggle debug log level for a watcher — Admin only.
     */
    #[Route('/{id}/debug', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function toggleDebug(string $id): JsonResponse
    {
        $watcher = $this->watcherRepository->find($id);
        if (!$watcher instanceof Watcher) {
            return $this->json(['error' => ['code' => 404, 'message' => 'Watcher not found']], 404);
        }

        $config = $watcher->getConfig();
        $currentLevel = $config['log_level'] ?? 'info';
        $newLevel = $currentLevel === 'debug' ? 'info' : 'debug';

        $watcher->mergeConfig(['log_level' => $newLevel]);
        $this->em->flush();

        // Push updated config to connected watcher
        $this->watcherCommandService->sendConfig($watcher);

        return $this->json(['data' => $this->serializeWatcher($watcher)]);
    }

    private function serializeWatcher(Watcher $watcher): array
    {
        return [
            'id' => (string)$watcher->getId(),
            'watcher_id' => $watcher->getWatcherId(),
            'name' => $watcher->getName(),
            'status' => $watcher->getStatus()->value,
            'hostname' => $watcher->getHostname(),
            'version' => $watcher->getVersion(),
            'config' => $watcher->getConfig(),
            'config_hash' => $watcher->getConfigHash(),
            'last_seen_at' => $watcher->getLastSeenAt()?->format('c'),
            'created_at' => $watcher->getCreatedAt()->format('c'),
            'updated_at' => $watcher->getUpdatedAt()->format('c'),
        ];
    }

    private function serializeLog(WatcherLog $log): array
    {
        return [
            'id' => (string)$log->getId(),
            'level' => $log->getLevel(),
            'message' => $log->getMessage(),
            'context' => $log->getContext(),
            'created_at' => $log->getCreatedAt()->format('c'),
        ];
    }
}
