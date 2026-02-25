<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Service to send commands to the watcher via the internal HTTP endpoint
 * of the WebSocket server (running in a separate process).
 */
class WatcherCommandService
{
    public function __construct(
        private string $watcherAuthToken,
        private LoggerInterface $logger,
        private string $wsInternalUrl = 'http://127.0.0.1:8081',
    ) {}

    /**
     * Request a scan of the given path.
     *
     * @return string The scan ID (UUID)
     */
    public function requestScan(string $hostPath): string
    {
        $scanId = (string) Uuid::v4();

        $command = [
            'type' => 'command.scan',
            'data' => [
                'path' => $hostPath,
                'scan_id' => $scanId,
            ],
        ];

        $this->sendCommand($command);

        return $scanId;
    }

    /**
     * Request the watcher to start watching a new path.
     */
    public function requestWatchAdd(string $path): void
    {
        $this->sendCommand([
            'type' => 'command.watch.add',
            'data' => ['path' => $path],
        ]);
    }

    /**
     * Request the watcher to stop watching a path.
     */
    public function requestWatchRemove(string $path): void
    {
        $this->sendCommand([
            'type' => 'command.watch.remove',
            'data' => ['path' => $path],
        ]);
    }

    /**
     * Get the WebSocket server status (connected watchers, etc.).
     */
    public function getStatus(): array
    {
        $url = $this->wsInternalUrl . '/internal/status';

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 5,
            ],
        ]);

        try {
            $response = @file_get_contents($url, false, $context);
            if ($response === false) {
                return ['connected_watchers' => 0, 'error' => 'WebSocket server unreachable'];
            }
            return json_decode($response, true) ?? [];
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get WS server status', ['error' => $e->getMessage()]);
            return ['connected_watchers' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send a command to the watcher via the internal HTTP endpoint.
     */
    private function sendCommand(array $command): void
    {
        $url = $this->wsInternalUrl . '/internal/send-to-watcher';
        $json = json_encode($command);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Content-Type: application/json',
                    'X-Internal-Token: ' . $this->watcherAuthToken,
                    'Content-Length: ' . strlen($json),
                ]),
                'content' => $json,
                'timeout' => 5,
            ],
        ]);

        try {
            $response = @file_get_contents($url, false, $context);
            if ($response === false) {
                $this->logger->warning('Failed to send command to WS server', [
                    'type' => $command['type'],
                    'url' => $url,
                ]);
                return;
            }

            $result = json_decode($response, true);
            $this->logger->info('Command sent to watcher', [
                'type' => $command['type'],
                'watchers' => $result['watchers'] ?? 0,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Error sending command to WS server', [
                'type' => $command['type'],
                'error' => $e->getMessage(),
            ]);
        }
    }
}
