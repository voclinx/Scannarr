<?php

declare(strict_types=1);

namespace App\WebSocket\Handler;

use App\Contract\WebSocket\WatcherMessageHandlerInterface;
use App\Entity\Volume;
use App\WebSocket\ScanStateManager;
use App\WebSocket\WatcherFileHelper;
use Psr\Log\LoggerInterface;

final readonly class ScanStartedHandler implements WatcherMessageHandlerInterface
{
    public function __construct(
        private ScanStateManager $scanState,
        private WatcherFileHelper $helper,
        private LoggerInterface $logger,
    ) {
    }

    public function supports(string $messageType): bool
    {
        return $messageType === 'scan.started';
    }

    public function handle(array $message): void
    {
        $data = $message['data'] ?? [];
        $scanId = $data['scan_id'] ?? null;
        $path = $data['path'] ?? null;

        if (!$scanId || !$path) {
            $this->logger->warning('Scan started with missing data', $data);

            return;
        }

        $volume = $this->helper->resolveVolume($path);
        if (!$volume instanceof Volume) {
            $this->logger->warning('No volume found for scan path', ['path' => $path]);

            return;
        }

        $this->scanState->startScan($scanId, $volume);

        $this->logger->info('Scan started', [
            'scan_id' => $scanId,
            'path' => $path,
            'volume' => $volume->getName(),
        ]);
    }
}
