<?php

namespace App\Service;

use App\Entity\Watcher;
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
    public function requestScan(string $hostPath, ?string $targetWatcherId = null): string
    {
        $scanId = (string)Uuid::v4();

        $command = [
            'type' => 'command.scan',
            'data' => [
                'path' => $hostPath,
                'scan_id' => $scanId,
            ],
        ];

        $this->sendCommand($command, $targetWatcherId);

        return $scanId;
    }

    /**
     * Request the watcher to start watching a new path.
     */
    public function requestWatchAdd(string $path, ?string $targetWatcherId = null): void
    {
        $this->sendCommand([
            'type' => 'command.watch.add',
            'data' => ['path' => $path],
        ], $targetWatcherId);
    }

    /**
     * Request the watcher to stop watching a path.
     */
    public function requestWatchRemove(string $path, ?string $targetWatcherId = null): void
    {
        $this->sendCommand([
            'type' => 'command.watch.remove',
            'data' => ['path' => $path],
        ], $targetWatcherId);
    }

    /**
     * Request the watcher to delete files.
     *
     * @param string $deletionId The ScheduledDeletion UUID
     * @param array $files Array of [media_file_id, volume_path, file_path]
     *
     * @return bool True if the command was sent to the watcher, false if watcher offline
     */
    public function requestFilesDelete(string $deletionId, array $files, ?string $targetWatcherId = null): bool
    {
        $requestId = (string)Uuid::v4();

        return $this->sendCommand([
            'type' => 'command.files.delete',
            'data' => [
                'request_id' => $requestId,
                'deletion_id' => $deletionId,
                'files' => $files,
            ],
        ], $targetWatcherId);
    }

    /**
     * Request the watcher to create a hardlink from source to target.
     *
     * @return bool True if command sent, false if watcher offline
     */
    public function requestHardlink(
        string $deletionId,
        string $sourcePath,
        string $targetPath,
        string $volumePath,
        ?string $targetWatcherId = null,
    ): bool {
        $requestId = (string)Uuid::v4();

        return $this->sendCommand([
            'type' => 'command.files.hardlink',
            'data' => [
                'request_id' => $requestId,
                'deletion_id' => $deletionId,
                'source_path' => $sourcePath,
                'target_path' => $targetPath,
                'volume_path' => $volumePath,
            ],
        ], $targetWatcherId);
    }

    /**
     * Send the current config to a specific watcher (e.g. after approval or config update).
     */
    public function sendConfig(Watcher $watcher): void
    {
        $config = $watcher->getConfig();

        $this->sendCommand([
            'type' => 'watcher.config',
            'data' => array_merge($config, [
                'auth_token' => $watcher->getAuthToken(),
                'config_hash' => $watcher->getConfigHash(),
            ]),
        ], $watcher->getWatcherId());
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
     * @param array $command The command payload
     * @param string|null $targetWatcherId If set, target a specific watcher; broadcast otherwise
     *
     * @return bool True if sent (HTTP 200), false if watcher offline (HTTP 503) or error
     */
    public function sendCommand(array $command, ?string $targetWatcherId = null): bool
    {
        if ($targetWatcherId !== null) {
            $command['target_watcher_id'] = $targetWatcherId;
        }

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
            if (preg_match('/^HTTP\/\d+\.?\d*\s+(\d+)/', (string)$header, $matches)) {
                return (int)$matches[1];
            }
        }

        return 0;
    }
}
