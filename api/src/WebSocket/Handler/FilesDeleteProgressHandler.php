<?php

declare(strict_types=1);

namespace App\WebSocket\Handler;

use App\Contract\WebSocket\WatcherMessageHandlerInterface;
use Psr\Log\LoggerInterface;

final class FilesDeleteProgressHandler implements WatcherMessageHandlerInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(string $messageType): bool
    {
        return $messageType === 'files.delete.progress';
    }

    public function handle(array $message): void
    {
        $data = $message['data'] ?? [];
        $this->logger->info('File deletion progress', [
            'deletion_id' => $data['deletion_id'] ?? '',
            'media_file_id' => $data['media_file_id'] ?? '',
            'status' => $data['status'] ?? '',
        ]);
    }
}
