<?php

declare(strict_types=1);

namespace App\WebSocket\Handler;

use App\Contract\WebSocket\WatcherMessageHandlerInterface;
use Psr\Log\LoggerInterface;

final readonly class WatcherStatusHandler implements WatcherMessageHandlerInterface
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function supports(string $messageType): bool
    {
        return $messageType === 'watcher.status';
    }

    public function handle(array $message): void
    {
        $data = $message['data'] ?? [];
        $this->logger->debug('Watcher status', [
            'watcher_id' => $message['_watcher_id'] ?? null,
            'status' => $data['status'] ?? 'unknown',
            'watched_paths' => $data['watched_paths'] ?? [],
            'uptime_seconds' => $data['uptime_seconds'] ?? 0,
        ]);
    }
}
