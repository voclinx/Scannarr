<?php

declare(strict_types=1);

namespace App\Contract\WebSocket;

interface WatcherMessageHandlerInterface
{
    public function supports(string $messageType): bool;

    /** @param array<string, mixed> $message */
    public function handle(array $message): void;
}
