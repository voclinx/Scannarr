<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Service to send commands to the watcher via the internal HTTP endpoint
 * of the WebSocket server (running in a separate process).
 */
class WatcherCommandService
{
    public function __construct(
        private readonly string $watcherAuthToken,
        private readonly LoggerInterface $logger,
        private readonly string $wsInternalUrl = 'http://127.0.0.1:8081',
    ) {
    }

    /**
     * Request a scan of the given path.
     *
     * @return string The scan ID (UUID)
     */
    public function requestScan(string $hostPath): string
    {
        $scanId = (string)Uuid::v4();

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
     * Request the watcher to delete files.
     *
     * @param string $deletionId The ScheduledDeletion UUID
     * @param array  $files      Array of [media_file_id, volume_path, file_path]
     *
     * @return bool True if the command was sent to the watcher, false if watcher offline
     */
    public function requestFilesDelete(string $deletionId, array $files): bool
    {
        $requestId = (string)Uuid::v4();

        return $this->sendCommand([
            'type' => 'command.files.delete',
            'data' => [
                'request_id' => $requestId,
                'deletion_id' => $deletionId,
                'files' => $files,
            ],
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
        } catch (Throwable $e) {
            $this->logger->error('Failed to get WS server status', ['error' => $e->getMessage()]);

            return ['connected_watchers' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send a command to the watcher via the internal HTTP endpoint.
     *
     * @return bool True if sent (HTTP 200), false if watcher offline (HTTP 503) or error
     */
    private function sendCommand(array $command): bool
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
                'ignore_errors' => true,
            ],
        ]);

        try {
            $response = @file_get_contents($url, false, $context);

            if ($response === false) {
                $this->logger->warning('Failed to send command to WS server (no response)', [
                    'type' => $command['type'],
                    'url' => $url,
                ]);

                return false;
            }

            $statusCode = $this->getHttpStatusCode($http_response_header ?? []);

            if ($statusCode === 503) {
                $this->logger->info('Watcher offline, command not sent', [
                    'type' => $command['type'],
                ]);

                return false;
            }

            if ($statusCode >= 200 && $statusCode < 300) {
                $result = json_decode($response, true);
                $this->logger->info('Command sent to watcher', [
                    'type' => $command['type'],
                    'watchers' => $result['watchers'] ?? 0,
                ]);

                return true;
            }

            $this->logger->warning('Unexpected response from WS server', [
                'type' => $command['type'],
                'status' => $statusCode,
                'response' => $response,
            ]);

            return false;
        } catch (Throwable $e) {
            $this->logger->error('Error sending command to WS server', [
                'type' => $command['type'],
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Extract HTTP status code from response headers.
     */
    private function getHttpStatusCode(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\d+\.?\d*\s+(\d+)/', $header, $matches)) {
                return (int)$matches[1];
            }
        }

        return 0;
    }
}
