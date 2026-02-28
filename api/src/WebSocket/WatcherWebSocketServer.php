<?php

declare(strict_types=1);

namespace App\WebSocket;

use App\Entity\Watcher;
use DateTimeImmutable;
use Exception;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Message;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\JWTDecodeFailureException;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;
use Ratchet\RFC6455\Handshake\RequestVerifier;
use Ratchet\RFC6455\Handshake\ServerNegotiator;
use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\FrameInterface;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\EventLoop\Loop;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;
use Throwable;

final class WatcherWebSocketServer
{
    /**
     * connId → {conn, buffer, authenticated, watcherId, watcherDbId}
     *
     * @var array<int, array{conn: ConnectionInterface, buffer: MessageBuffer, authenticated: bool, watcherId: string|null, watcherDbId: string|null}>
     */
    private array $connections = [];

    /**
     * watcherId → connId (inverse mapping for targeted sends).
     *
     * @var array<string, int>
     */
    private array $watcherConnections = [];

    /**
     * Browser client connections: connId → {conn, buffer, authenticated}
     *
     * @var array<int, array{conn: ConnectionInterface, buffer: MessageBuffer, authenticated: bool}>
     */
    private array $browserConnections = [];

    public function __construct(
        private readonly string $internalToken,
        private readonly LoggerInterface $logger,
        private readonly WatcherMessageDispatcher $messageDispatcher,
        private readonly WatcherLifecycleService $lifecycleService,
        private readonly JWTEncoderInterface $jwtEncoder,
    ) {
    }

    public function run(string $host = '0.0.0.0', int $port = 8081): void
    {
        $negotiator = new ServerNegotiator(new RequestVerifier(), new HttpFactory());

        // Main WebSocket socket
        $socket = new SocketServer("{$host}:{$port}");

        $socket->on('connection', function (ConnectionInterface $conn) use ($negotiator): void {
            $connId = spl_object_id($conn);
            $this->logger->info('New TCP connection', ['id' => $connId]);

            $httpBuffer = '';

            // Phase 1: HTTP upgrade handshake or internal HTTP command
            $conn->on('data', $onHttpData = function (string $data) use ($connId, $conn, $negotiator, &$httpBuffer, &$onHttpData): void {
                $httpBuffer .= $data;

                // Wait for complete HTTP headers
                if (!str_contains($httpBuffer, "\r\n\r\n")) {
                    return;
                }

                // Remove this initial HTTP handler
                $conn->removeListener('data', $onHttpData);

                try {
                    $request = Message::parseRequest($httpBuffer);
                } catch (Throwable $e) {
                    $this->logger->warning('Invalid HTTP request', ['id' => $connId, 'error' => $e->getMessage()]);
                    $conn->end("HTTP/1.1 400 Bad Request\r\nContent-Length: 0\r\n\r\n");

                    return;
                }

                $path = $request->getUri()->getPath();
                $method = strtoupper($request->getMethod());

                // Internal HTTP command endpoint
                if ($path === '/internal/send-to-watcher' && $method === 'POST') {
                    $this->handleInternalCommand($conn, $request);

                    return;
                }

                // Internal status endpoint
                if ($path === '/internal/status' && $method === 'GET') {
                    $this->handleInternalStatus($conn);

                    return;
                }

                // WebSocket upgrade paths
                if ($path !== '/ws/watcher' && $path !== '/ws/events') {
                    $conn->end("HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\n\r\n");

                    return;
                }

                // WebSocket handshake
                $response = $negotiator->handshake($request);

                if ($response->getStatusCode() !== 101) {
                    $statusCode = $response->getStatusCode();
                    $conn->end("HTTP/1.1 {$statusCode} Error\r\nContent-Length: 0\r\n\r\n");

                    return;
                }

                // Send 101 Switching Protocols response
                $responseStr = Message::toString($response);
                $conn->write($responseStr);

                $this->logger->info('WebSocket handshake complete', ['id' => $connId, 'path' => $path]);

                // Phase 2: WebSocket message handling — dispatch by path
                if ($path === '/ws/events') {
                    $this->setupBrowserConnection($connId, $conn);
                } else {
                    $this->setupWebSocketConnection($connId, $conn);
                }
            });

            // Auth timeout: 30s for connections that have NOT sent a watcher.hello
            // (PENDING watchers may stay connected indefinitely)
            Loop::addTimer(30.0, function () use ($connId): void {
                if (isset($this->connections[$connId])
                    && !$this->connections[$connId]['authenticated']
                    && $this->connections[$connId]['watcherId'] === null
                ) {
                    $this->logger->warning('Connection auth timeout (no hello received)', ['id' => $connId]);
                    $this->closeConnection($connId);
                }

                // Also timeout unauthenticated browser connections
                if (isset($this->browserConnections[$connId])
                    && !$this->browserConnections[$connId]['authenticated']
                ) {
                    $this->logger->warning('Browser connection auth timeout', ['id' => $connId]);
                    $this->closeBrowserConnection($connId);
                }
            });
        });

        $this->logger->info("WebSocket server started on {$host}:{$port}");
        echo "WebSocket server listening on ws://{$host}:{$port}/ws/watcher\n";
        echo "Browser events available at ws://{$host}:{$port}/ws/events\n";
        echo "Internal API available at http://{$host}:{$port}/internal/send-to-watcher\n";
    }

    /**
     * Handle internal HTTP POST to send a command to the watcher.
     * Used by the API (PHP-FPM) to communicate with the watcher process.
     */
    private function handleInternalCommand(ConnectionInterface $conn, RequestInterface $request): void
    {
        $body = (string)$request->getBody();

        if ($body === '' || $body === '0') {
            $response = '{"error":"Empty body"}';
            $conn->end("HTTP/1.1 400 Bad Request\r\nContent-Type: application/json\r\nContent-Length: " . strlen($response) . "\r\n\r\n" . $response);

            return;
        }

        $data = json_decode($body, true);
        if (!$data) {
            $response = '{"error":"Invalid JSON"}';
            $conn->end("HTTP/1.1 400 Bad Request\r\nContent-Type: application/json\r\nContent-Length: " . strlen($response) . "\r\n\r\n" . $response);

            return;
        }

        // Verify internal auth token
        $token = $request->getHeaderLine('X-Internal-Token');
        if ($token !== $this->internalToken) {
            $response = '{"error":"Unauthorized"}';
            $conn->end("HTTP/1.1 401 Unauthorized\r\nContent-Type: application/json\r\nContent-Length: " . strlen($response) . "\r\n\r\n" . $response);

            return;
        }

        // Extract optional target_watcher_id
        $targetWatcherId = $data['target_watcher_id'] ?? null;

        $connectedCount = count($this->watcherConnections);

        if ($connectedCount === 0 && $targetWatcherId === null) {
            $response = '{"error":"No watcher connected"}';
            $conn->end("HTTP/1.1 503 Service Unavailable\r\nContent-Type: application/json\r\nContent-Length: " . strlen($response) . "\r\n\r\n" . $response);

            return;
        }

        // Send to target or broadcast
        $this->sendToWatcher($body, $targetWatcherId);

        $response = json_encode(['ok' => true, 'watchers' => $connectedCount]);
        $conn->end("HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($response) . "\r\n\r\n" . $response);

        $this->logger->info('Internal command sent to watcher', [
            'type' => $data['type'] ?? 'unknown',
            'target' => $targetWatcherId,
        ]);
    }

    /**
     * Handle internal GET status request.
     */
    private function handleInternalStatus(ConnectionInterface $conn): void
    {
        $watchers = [];
        foreach ($this->watcherConnections as $watcherId => $connId) {
            $watchers[] = [
                'watcher_id' => $watcherId,
                'conn_id' => $connId,
                'authenticated' => $this->connections[$connId]['authenticated'] ?? false,
            ];
        }

        $response = json_encode([
            'connected_watchers' => count($this->watcherConnections),
            'total_connections' => count($this->connections),
            'browser_clients' => count($this->browserConnections),
            'watchers' => $watchers,
        ]);
        $conn->end("HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($response) . "\r\n\r\n" . $response);
    }

    // ── Browser WebSocket (frontend /ws/events) ─────────────────────────

    /**
     * Set up a browser WebSocket connection (subscribes to real-time events).
     */
    private function setupBrowserConnection(int $connId, ConnectionInterface $conn): void
    {
        $closeFrameChecker = new CloseFrameChecker();
        $messageBuffer = new MessageBuffer(
            $closeFrameChecker,
            function (MessageInterface $message) use ($connId): void {
                $this->handleBrowserMessage($connId, (string)$message);
            },
            function (FrameInterface $frame) use ($connId, $conn): void {
                if ($frame->getOpcode() === Frame::OP_PING) {
                    $pong = new Frame($frame->getPayload(), true, Frame::OP_PONG);
                    $conn->write($pong->getContents());
                } elseif ($frame->getOpcode() === Frame::OP_CLOSE) {
                    $this->closeBrowserConnection($connId);
                    $conn->close();
                }
            },
            true,
        );

        $this->browserConnections[$connId] = [
            'conn' => $conn,
            'buffer' => $messageBuffer,
            'authenticated' => false,
        ];

        $conn->on('data', function (string $data) use ($messageBuffer): void {
            $messageBuffer->onData($data);
        });

        $conn->on('close', function () use ($connId): void {
            $this->closeBrowserConnection($connId);
        });

        $conn->on('error', function (Exception $e) use ($connId): void {
            $this->logger->error('Browser connection error', ['id' => $connId, 'error' => $e->getMessage()]);
            $this->closeBrowserConnection($connId);
        });
    }

    private function handleBrowserMessage(int $connId, string $payload): void
    {
        $data = json_decode($payload, true);
        if (!$data || !isset($data['type'])) {
            return;
        }

        if ($data['type'] === 'browser.auth') {
            $this->handleBrowserAuth($connId, $data['data'] ?? []);

            return;
        }

        // Ignore all other messages from unauthenticated browsers
        if (!($this->browserConnections[$connId]['authenticated'] ?? false)) {
            return;
        }

        // Authenticated browsers can send ping-like messages — just ignore them
    }

    private function handleBrowserAuth(int $connId, array $data): void
    {
        $token = $data['token'] ?? '';

        if ($token === '') {
            $this->closeBrowserConnection($connId);

            return;
        }

        try {
            $payload = $this->jwtEncoder->decode($token);
        } catch (JWTDecodeFailureException $e) {
            $this->logger->warning('Browser auth failed — invalid JWT', ['id' => $connId]);
            $this->sendToBrowser($connId, ['type' => 'browser.rejected', 'data' => ['reason' => 'invalid_token']]);
            $this->closeBrowserConnection($connId);

            return;
        }

        if (!is_array($payload)) {
            $this->closeBrowserConnection($connId);

            return;
        }

        $this->browserConnections[$connId]['authenticated'] = true;
        $this->logger->info('Browser client authenticated', ['id' => $connId, 'user' => $payload['username'] ?? $payload['email'] ?? 'unknown']);

        // Build current watcher status list
        $connectedWatcherIds = array_keys($this->watcherConnections);
        $this->sendToBrowser($connId, [
            'type' => 'browser.ready',
            'data' => ['connected_watcher_ids' => $connectedWatcherIds],
        ]);
    }

    /**
     * Send a JSON payload to a specific browser connection via WebSocket frame.
     */
    private function sendToBrowser(int $connId, array $data): void
    {
        if (!isset($this->browserConnections[$connId])) {
            return;
        }

        $json = json_encode($data);
        $frame = new Frame($json, true, Frame::OP_TEXT);
        $this->browserConnections[$connId]['conn']->write($frame->getContents());
    }

    /**
     * Broadcast a message to all authenticated browser connections.
     */
    public function broadcastToAllBrowsers(string $type, array $data): void
    {
        if (empty($this->browserConnections)) {
            return;
        }

        $payload = ['type' => $type, 'data' => $data];

        foreach ($this->browserConnections as $connId => $browserConn) {
            if ($browserConn['authenticated']) {
                $this->sendToBrowser($connId, $payload);
            }
        }
    }

    private function closeBrowserConnection(int $connId): void
    {
        if (isset($this->browserConnections[$connId])) {
            $this->logger->debug('Browser connection closed', ['id' => $connId]);
            unset($this->browserConnections[$connId]);
        }
    }

    // ── Watcher WebSocket (Go binary /ws/watcher) ───────────────────────

    private function setupWebSocketConnection(int $connId, ConnectionInterface $conn): void
    {
        $closeFrameChecker = new CloseFrameChecker();
        $messageBuffer = new MessageBuffer(
            $closeFrameChecker,
            function (MessageInterface $message) use ($connId): void {
                $this->handleMessage($connId, (string)$message);
            },
            function (FrameInterface $frame) use ($connId, $conn): void {
                if ($frame->getOpcode() === Frame::OP_PING) {
                    $pong = new Frame($frame->getPayload(), true, Frame::OP_PONG);
                    $conn->write($pong->getContents());
                } elseif ($frame->getOpcode() === Frame::OP_CLOSE) {
                    $this->handleClose($connId);
                    $conn->close();
                }
            },
            true,
        );

        $this->connections[$connId] = [
            'conn' => $conn,
            'buffer' => $messageBuffer,
            'authenticated' => false,
            'watcherId' => null,
            'watcherDbId' => null,
        ];

        $conn->on('data', function (string $data) use ($messageBuffer): void {
            $messageBuffer->onData($data);
        });

        $conn->on('close', function () use ($connId): void {
            $this->handleClose($connId);
        });

        $conn->on('error', function (Exception $e) use ($connId): void {
            $this->logger->error('Connection error', ['id' => $connId, 'error' => $e->getMessage()]);
            $this->handleClose($connId);
        });
    }

    /**
     * Send a JSON string to a specific watcher (by watcherId) or broadcast to all authenticated.
     */
    public function sendToWatcher(string $json, ?string $targetWatcherId = null): void
    {
        if ($targetWatcherId !== null) {
            // Targeted send — also try PENDING connections (for watcher.config after approval)
            if (isset($this->watcherConnections[$targetWatcherId])) {
                $connId = $this->watcherConnections[$targetWatcherId];
                $this->sendToConnection($connId, json_decode($json, true) ?? []);
            } else {
                // Watcher not connected — log silently
                $this->logger->debug('Target watcher not connected', ['watcher_id' => $targetWatcherId]);
            }

            return;
        }

        // Broadcast to all authenticated connections
        foreach ($this->watcherConnections as $connId) {
            if (isset($this->connections[$connId]) && $this->connections[$connId]['authenticated']) {
                $frame = new Frame($json, true, Frame::OP_TEXT);
                $this->connections[$connId]['conn']->write($frame->getContents());
            }
        }
    }

    /**
     * Send data to a specific connection.
     */
    private function sendToConnection(int $connId, array $data): void
    {
        if (!isset($this->connections[$connId])) {
            return;
        }

        $json = json_encode($data);
        $frame = new Frame($json, true, Frame::OP_TEXT);
        $this->connections[$connId]['conn']->write($frame->getContents());
    }

    private function handleMessage(int $connId, string $payload): void
    {
        $data = json_decode($payload, true);
        if (!$data || !isset($data['type'])) {
            $this->logger->warning('Invalid message format', ['id' => $connId]);

            return;
        }

        // ── New protocol: watcher.hello / watcher.auth ──

        if ($data['type'] === 'watcher.hello') {
            $this->handleWatcherHello($connId, $data['data'] ?? []);

            return;
        }

        if ($data['type'] === 'watcher.auth') {
            $this->handleWatcherAuth($connId, $data['data'] ?? []);

            return;
        }

        // ── Legacy fallback: auth message (backward compat) ──
        if ($data['type'] === 'auth') {
            $token = $data['data']['token'] ?? '';
            if ($token === $this->internalToken) {
                $this->connections[$connId]['authenticated'] = true;
                $this->logger->info('Watcher authenticated (legacy)', ['id' => $connId]);
                $this->processPendingDeletionsOnReconnect($connId);
            } else {
                $this->logger->warning('Invalid legacy auth token', ['id' => $connId]);
                $this->closeConnection($connId);
            }

            return;
        }

        // ── Require authentication for all other messages ──
        if (!($this->connections[$connId]['authenticated'] ?? false)) {
            $this->logger->warning('Message from unauthenticated connection', ['id' => $connId, 'type' => $data['type']]);

            return;
        }

        // Inject watcher_id before delegating to processor
        $watcherId = $this->connections[$connId]['watcherId'] ?? null;
        $data['_watcher_id'] = $watcherId;

        // Delegate to message processor
        try {
            $this->messageDispatcher->dispatch($data);
        } catch (Throwable $e) {
            $this->logger->error('Message processing error', [
                'type' => $data['type'],
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle watcher.hello — first message from a new-protocol watcher.
     */
    private function handleWatcherHello(int $connId, array $data): void
    {
        $watcherId = $data['watcher_id'] ?? null;
        $hostname = $data['hostname'] ?? null;
        $version = $data['version'] ?? null;

        if ($watcherId === null || $watcherId === '') {
            $this->logger->warning('watcher.hello without watcher_id', ['id' => $connId]);
            $this->closeConnection($connId);

            return;
        }

        try {
            $watcher = $this->lifecycleService->findOrCreateWatcher($watcherId, $hostname, $version);
        } catch (Throwable $e) {
            $this->logger->error('Failed to find/create watcher', [
                'id' => $connId,
                'watcher_id' => $watcherId,
                'error' => $e->getMessage(),
            ]);
            $this->closeConnection($connId);

            return;
        }

        // Update connId→watcherId mapping
        $this->connections[$connId]['watcherId'] = $watcherId;

        // Also register in watcherConnections for targeted sends
        $this->watcherConnections[$watcherId] = $connId;

        if ($watcher->getStatus()->value === 'revoked') {
            $this->logger->info('Revoked watcher attempted connection', [
                'watcher_id' => $watcherId,
                'id' => $connId,
            ]);
            $this->sendToConnection($connId, ['type' => 'watcher.rejected', 'data' => ['reason' => 'revoked']]);
            $this->closeConnection($connId);

            return;
        }

        if ($watcher->getStatus()->value === 'pending') {
            $this->logger->info('Watcher pending approval', ['watcher_id' => $watcherId, 'id' => $connId]);
            $this->sendToConnection($connId, ['type' => 'watcher.pending', 'data' => ['watcher_id' => $watcherId]]);

            return; // Stay connected, waiting for admin approval
        }

        // Watcher is approved/connected/disconnected — ask for auth
        $this->sendToConnection($connId, ['type' => 'watcher.auth_required', 'data' => ['watcher_id' => $watcherId]]);
    }

    /**
     * Handle watcher.auth — token authentication from a known watcher.
     */
    private function handleWatcherAuth(int $connId, array $data): void
    {
        $token = $data['token'] ?? '';

        if ($token === '') {
            $this->closeConnection($connId);

            return;
        }

        try {
            $watcher = $this->lifecycleService->authenticateWatcher($token);
        } catch (Throwable $e) {
            $this->logger->error('Auth error', ['id' => $connId, 'error' => $e->getMessage()]);
            $this->closeConnection($connId);

            return;
        }

        if (!$watcher instanceof Watcher) {
            $this->logger->warning('Invalid auth token', ['id' => $connId]);
            $this->sendToConnection($connId, ['type' => 'watcher.rejected', 'data' => ['reason' => 'invalid_token']]);
            $this->closeConnection($connId);

            return;
        }

        $watcherId = $watcher->getWatcherId();
        $watcherDbId = (string)$watcher->getId();

        $this->connections[$connId]['authenticated'] = true;
        $this->connections[$connId]['watcherId'] = $watcherId;
        $this->connections[$connId]['watcherDbId'] = $watcherDbId;
        $this->watcherConnections[$watcherId] = $connId;

        $this->logger->info('Watcher authenticated', ['watcher_id' => $watcherId, 'id' => $connId]);

        // Send current config — transform watch_paths from [{path, name}] to flat string array for the Go watcher
        $config = $watcher->getConfig();
        $config['watch_paths'] = array_values(array_filter(array_map(
            static fn ($wp) => is_array($wp) ? ($wp['path'] ?? '') : (string)$wp,
            $config['watch_paths'] ?? [],
        )));
        $configPayload = array_merge($config, ['config_hash' => $watcher->getConfigHash()]);
        $this->sendToConnection($connId, ['type' => 'watcher.config', 'data' => $configPayload]);

        // Broadcast watcher status to all browser clients
        $this->broadcastToAllBrowsers('watcher.status_changed', [
            'id' => $watcherDbId,
            'watcher_id' => $watcherId,
            'status' => 'connected',
            'last_seen_at' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
        ]);

        // Resend pending deletions on reconnect
        $this->processPendingDeletionsOnReconnect($connId);
    }

    /**
     * On watcher reconnection, resend all pending deletion commands.
     * This handles the case where the watcher was offline when a deletion was initiated.
     */
    private function processPendingDeletionsOnReconnect(int $connId): void
    {
        try {
            $pendingCommands = $this->lifecycleService->getPendingDeletionCommands();
            foreach ($pendingCommands as $command) {
                $this->sendToConnection($connId, $command);
            }
            if (count($pendingCommands) > 0) {
                $this->logger->info('Sent pending deletions to reconnected watcher', [
                    'count' => count($pendingCommands),
                ]);
            }
        } catch (Throwable $e) {
            $this->logger->error('Failed to process pending deletions on reconnect', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function handleClose(int $connId): void
    {
        $watcherId = $this->connections[$connId]['watcherId'] ?? null;
        $watcherDbId = $this->connections[$connId]['watcherDbId'] ?? null;

        $this->logger->info('Connection closed', ['id' => $connId, 'watcher_id' => $watcherId]);

        unset($this->connections[$connId]);

        if ($watcherId !== null) {
            // Clean up watcherConnections only if it points to this connId
            if (($this->watcherConnections[$watcherId] ?? null) === $connId) {
                unset($this->watcherConnections[$watcherId]);
            }

            // Update watcher status in DB
            try {
                $this->lifecycleService->handleWatcherDisconnect($watcherId);
            } catch (Throwable $e) {
                $this->logger->error('Failed to update watcher status on disconnect', [
                    'watcher_id' => $watcherId,
                    'error' => $e->getMessage(),
                ]);
            }

            // Broadcast disconnection to all browser clients
            if ($watcherDbId !== null) {
                $this->broadcastToAllBrowsers('watcher.status_changed', [
                    'id' => $watcherDbId,
                    'watcher_id' => $watcherId,
                    'status' => 'disconnected',
                    'last_seen_at' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
                ]);
            }
        }
    }

    private function closeConnection(int $connId): void
    {
        if (isset($this->connections[$connId])) {
            $closeFrame = new Frame('', true, Frame::OP_CLOSE);
            $this->connections[$connId]['conn']->write($closeFrame->getContents());
            $this->connections[$connId]['conn']->close();
        }
        $this->handleClose($connId);
    }
}
