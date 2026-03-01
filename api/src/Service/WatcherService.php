<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Watcher;
use App\Entity\WatcherLog;
use App\Enum\WatcherStatus;
use App\Repository\WatcherLogRepository;
use App\Repository\WatcherRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class WatcherService
{
    public function __construct(
        private WatcherRepository $watcherRepository,
        private WatcherLogRepository $watcherLogRepository,
        private EntityManagerInterface $em,
        private WatcherCommandService $watcherCommandService,
        private WatcherVolumeSyncService $volumeSyncService,
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function list(): array
    {
        return array_map($this->serializeWatcher(...), $this->watcherRepository->findAllOrderedByName());
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        $watcher = $this->watcherRepository->find($id);

        return $watcher instanceof Watcher ? $this->serializeWatcher($watcher) : null;
    }

    /**
     * Approve a pending watcher.
     *
     * @return array{result: string, data?: array<string, mixed>}
     */
    public function approve(string $id): array
    {
        $watcher = $this->watcherRepository->find($id);
        if (!$watcher instanceof Watcher) {
            return ['result' => 'not_found'];
        }
        if ($watcher->getStatus() === WatcherStatus::REVOKED) {
            return ['result' => 'revoked'];
        }

        $token = bin2hex(random_bytes(32));
        $watcher->setAuthToken($token);
        $watcher->setStatus(WatcherStatus::APPROVED);
        $this->em->flush();

        $this->volumeSyncService->sync($watcher->getConfig()['watch_paths'] ?? []);
        $this->watcherCommandService->sendConfig($watcher);

        return ['result' => 'ok', 'data' => $this->serializeWatcher($watcher)];
    }

    /**
     * Update watcher config.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>|null
     */
    public function updateConfig(string $id, array $config): ?array
    {
        $watcher = $this->watcherRepository->find($id);
        if (!$watcher instanceof Watcher) {
            return null;
        }

        $watcher->mergeConfig($config);
        $this->em->flush();

        if (isset($config['watch_paths'])) {
            $this->volumeSyncService->sync($config['watch_paths']);
        }

        $this->watcherCommandService->sendConfig($watcher);

        return $this->serializeWatcher($watcher);
    }

    /**
     * Rename a watcher.
     *
     * @return array{result: string, data?: array<string, mixed>}
     */
    public function updateName(string $id, string $name): array
    {
        $watcher = $this->watcherRepository->find($id);
        if (!$watcher instanceof Watcher) {
            return ['result' => 'not_found'];
        }
        if ($name === '') {
            return ['result' => 'empty_name'];
        }

        $watcher->setName($name);
        $this->em->flush();

        return ['result' => 'ok', 'data' => $this->serializeWatcher($watcher)];
    }

    /** @return array<string, mixed>|null */
    public function revoke(string $id): ?array
    {
        $watcher = $this->watcherRepository->find($id);
        if (!$watcher instanceof Watcher) {
            return null;
        }

        $watcher->setStatus(WatcherStatus::REVOKED);
        $watcher->setAuthToken(null);
        $this->em->flush();

        return $this->serializeWatcher($watcher);
    }

    public function delete(string $id): bool
    {
        $watcher = $this->watcherRepository->find($id);
        if (!$watcher instanceof Watcher) {
            return false;
        }

        $this->em->remove($watcher);
        $this->em->flush();

        return true;
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function getLogs(string $id, ?string $level, int $limit, int $offset): ?array
    {
        $watcher = $this->watcherRepository->find($id);
        if (!$watcher instanceof Watcher) {
            return null;
        }

        $logs = $this->watcherLogRepository->findByWatcher($watcher, $level !== '' ? $level : null, $limit, $offset);
        $total = $this->watcherLogRepository->countByWatcher($watcher, $level !== '' ? $level : null);

        return [
            'data' => array_map($this->serializeLog(...), $logs),
            'meta' => ['total' => $total, 'limit' => $limit, 'offset' => $offset],
        ];
    }

    /** @return array{result: string, data?: array<string, mixed>}|null */
    public function toggleDebug(string $id): ?array
    {
        $watcher = $this->watcherRepository->find($id);
        if (!$watcher instanceof Watcher) {
            return null;
        }

        $config = $watcher->getConfig();
        $currentLevel = $config['log_level'] ?? 'info';
        $newLevel = $currentLevel === 'debug' ? 'info' : 'debug';

        $watcher->mergeConfig(['log_level' => $newLevel]);
        $this->em->flush();

        $this->watcherCommandService->sendConfig($watcher);

        return $this->serializeWatcher($watcher);
    }

    /** @return array<string, mixed> */
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

    /** @return array<string, mixed> */
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
