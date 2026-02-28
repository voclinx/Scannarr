<?php

declare(strict_types=1);

namespace App\WebSocket\Handler;

use App\Contract\WebSocket\WatcherMessageHandlerInterface;
use Psr\Log\LoggerInterface;

final class ScanProgressHandler implements WatcherMessageHandlerInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(string $messageType): bool
    {
        return $messageType === 'scan.progress';
    }

    public function handle(array $message): void
    {
        $data = $message['data'] ?? [];
        $this->logger->debug('Scan progress', [
            'scan_id' => $data['scan_id'] ?? 'unknown',
            'files_scanned' => $data['files_scanned'] ?? 0,
            'dirs_scanned' => $data['dirs_scanned'] ?? 0,
        ]);
    }
}
