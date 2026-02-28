<?php

declare(strict_types=1);

namespace App\WebSocket\Handler;

use App\Contract\WebSocket\WatcherMessageHandlerInterface;
use App\Entity\Watcher;
use App\Entity\WatcherLog;
use App\Repository\WatcherRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

final class WatcherLogHandler implements WatcherMessageHandlerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WatcherRepository $watcherRepository,
    ) {
    }

    public function supports(string $messageType): bool
    {
        return $messageType === 'watcher.log';
    }

    public function handle(array $message): void
    {
        $data = $message['data'] ?? [];
        $watcherId = $message['_watcher_id'] ?? null;

        if ($watcherId === null) {
            return;
        }

        $watcher = $this->watcherRepository->findByWatcherId($watcherId);
        if (!$watcher instanceof Watcher) {
            return;
        }

        $log = new WatcherLog();
        $log->setWatcher($watcher);
        $log->setLevel(strtolower($data['level'] ?? 'info'));
        $log->setMessage($data['message'] ?? '');
        $log->setContext($data['context'] ?? []);

        if (isset($data['timestamp'])) {
            try {
                $log->setCreatedAt(new DateTimeImmutable($data['timestamp']));
            } catch (Throwable) {
                // keep default createdAt
            }
        }

        $this->em->persist($log);
        $this->em->flush();
    }
}
