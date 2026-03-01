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

/**
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 */
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
        $socket = new SocketServer("{$host}:{$port}");

        $socket->on('connection', function (ConnectionInterface $conn) use ($negotiator): void {
            $connId = spl_object_id($conn);
            $this->logger->info('New TCP connection', ['id' => $connId]);

            $httpBuffer = '';
            $conn->on('data', $onHttpData = function (string $data) use ($connId, $conn, $negotiator, &$httpBuffer, &$onHttpData): void {
                $httpBuffer .= $data;
                if (!str_contains($httpBuffer, "\r\n\r\n")) {
                    return;
                }
                $conn->removeListener('data', $onHttpData);
                $this->handleHttpRequest($connId, $conn, $negotiator, $httpBuffer);
            });

            $this->setupAuthTimeout($connId);
        });

        $this->logger->info("WebSocket server started on {$host}:{$port}");
        echo "WebSocket server listening on ws://{$host}:{$port}/ws/watcher\n";
        echo "Browser events available at ws://{$host}:{$port}/ws/events\n";
        echo "Internal API available at http://{$host}:{$port}/internal/send-to-watcher\n";
    }

    /**
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function handleHttpRequest(int $connId, ConnectionInterface $conn, ServerNegotiator $negotiator, string $httpBuffer): void
    {
        try {
            $request = Message::parseRequest($httpBuffer);
        } catch (Throwable $e) {
            $this->logger->warning('Invalid HTTP request', ['id' => $connId, 'error' => $e->getMessage()]);
            $conn->end("HTTP/1.1 400 Bad Request\r\nContent-Length: 0\r\n\r\n");

            return;
        }

        $path = $request->getUri()->getPath();
        $method = strtoupper($request->getMethod());

        if ($path === '/internal/send-to-watcher' && $method === 'POST') {
            $this->handleInternalCommand($conn, $request);

            return;
        }

        if ($path === '/internal/status' && $method === 'GET') {
            $this->handleInternalStatus($conn);

            return;
        }

        if ($path !== '/ws/watcher' && $path !== '/ws/events') {
            $conn->end("HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\n\r\n");

            return;
        }

        $response = $negotiator->handshake($request);

        if ($response->getStatusCode() !== 101) {
            $statusCode = $response->getStatusCode();
            $conn->end("HTTP/1.1 {$statusCode} Error\r\nContent-Length: 0\r\n\r\n");

            return;
        }

        $conn->write(Message::toString($response));
        $this->logger->info('WebSocket handshake complete', ['id' => $connId, 'path' => $path]);

        if ($path === '/ws/events') {
            $this->setupBrowserConnection($connId, $conn);

            return;
        }

        $this->setupWebSocketConnection($connId, $conn);
    }

    private function setupAuthTimeout(int $connId): void
    {
        Loop::addTimer(30.0, function () use ($connId): void {
            if (isset($this->connections[$connId])
                && !$this->connections[$connId]['authenticated']
                && $this->connections[$connId]['watcherId'] === null
            ) {
                $this->logger->warning('Connection auth timeout (no hello received)', ['id' => $connId]);
                $this->closeConnection($connId);
            }

            if (isset($this->browserConnections[$connId])
                && !$this->browserConnections[$connId]['authenticated']
            ) {
                $this->logger->warning('Browser connection auth timeout', ['id' => $connId]);
                $this->closeBrowserConnection($connId);
            }
        });
    }

    private function sendHttpResponse(ConnectionInterface $conn, int $statusCode, string $statusText, string $body): void
    {
        $conn->end("HTTP/1.1 {$statusCode} {$statusText}\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body);
    }

    /**
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     *
     * Handle internal HTTP POST to send a command to the watcher.
     * Used by the API (PHP-FPM) to communicate with the watcher process.
     */
    private function handleInternalCommand(ConnectionInterface $conn, RequestInterface $request): void
    {
        $body = (string)$request->getBody();

        if ($body === '' || $body === '0') {
            $this->sendHttpResponse($conn, 400, 'Bad Request', '{"error":"Empty body"}');

            return;
        }

        $data = json_decode($body, true);
        if (!$data) {
            $this->sendHttpResponse($conn, 400, 'Bad Request', '{"error":"Invalid JSON"}');

            return;
        }

        if ($request->getHeaderLine('X-Internal-Token') !== $this->internalToken) {
            $this->sendHttpResponse($conn, 401, 'Unauthorized', '{"error":"Unauthorized"}');

            return;
        }

        $targetWatcherId = $data['target_watcher_id'] ?? null;
        $connectedCount = count($this->watcherConnections);

        if ($connectedCount === 0 && $targetWatcherId === null) {
            $this->sendHttpResponse($conn, 503, 'Service Unavailable', '{"error":"No watcher connected"}');

            return;
        }

        $this->sendToWatcher($body, $targetWatcherId);
        $this->sendHttpResponse($conn, 200, 'OK', (string)json_encode(['ok' => true, 'watchers' => $connectedCount]));
        $this->logger->info('Internal command sent to watcher', ['type' => $data['type'] ?? 'unknown', 'target' => $targetWatcherId]);
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

        $response = (string)json_encode([
            'connected_watchers' => count($this->watcherConnections),
            'total_connections' => count($this->connections),
            'browser_clients' => count($this->browserConnections),
            'watchers' => $watchers,
        ]);
        $this->sendHttpResponse($conn, 200, 'OK', $response);
    }

    // ── Browser WebSocket (frontend /ws/events) ─────────────────────────

    /**
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     *
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

    /** @SuppressWarnings(PHPMD.ExcessiveMethodLength) */
    private function handleBrowserAuth(int $connId, array $data): void
    {
        $token = $data['token'] ?? '';

        if ($token === '') {
            $this->closeBrowserConnection($connId);

            return;
        }

        try {
            $payload = $this->jwtEncoder->decode($token);
        } catch (JWTDecodeFailureException) {
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
        if ($this->browserConnections === []) {
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

    /** @SuppressWarnings(PHPMD.ExcessiveMethodLength) */
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
            if (isset($this->watcherConnections[$targetWatcherId])) {
                $connId = $this->watcherConnections[$targetWatcherId];
                $this->sendToConnection($connId, json_decode($json, true) ?? []);

                return;
            }

            // Watcher not connected — log silently
            $this->logger->debug('Target watcher not connected', ['watcher_id' => $targetWatcherId]);

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

        if ($data['type'] === 'watcher.hello') {
            $this->handleWatcherHello($connId, $data['data'] ?? []);

            return;
        }

        if ($data['type'] === 'watcher.auth') {
            $this->handleWatcherAuth($connId, $data['data'] ?? []);

            return;
        }

        if ($data['type'] === 'auth') {
            $this->handleLegacyAuth($connId, $data['data']['token'] ?? '');

            return;
        }

        $this->dispatchAuthenticatedMessage($connId, $data);
    }

    private function handleLegacyAuth(int $connId, string $token): void
    {
        if ($token === $this->internalToken) {
            $this->connections[$connId]['authenticated'] = true;
            $this->logger->info('Watcher authenticated (legacy)', ['id' => $connId]);
            $this->processPendingDeletionsOnReconnect($connId);

            return;
        }

        $this->logger->warning('Invalid legacy auth token', ['id' => $connId]);
        $this->closeConnection($connId);
    }

    private function dispatchAuthenticatedMessage(int $connId, array $data): void
    {
        if (!($this->connections[$connId]['authenticated'] ?? false)) {
            $this->logger->warning('Message from unauthenticated connection', ['id' => $connId, 'type' => $data['type']]);

            return;
        }

        $data['_watcher_id'] = $this->connections[$connId]['watcherId'] ?? null;

        try {
            $this->messageDispatcher->dispatch($data);
        } catch (Throwable $e) {
            $this->logger->error('Message processing error', ['type' => $data['type'], 'error' => $e->getMessage()]);
        }
    }

    /**
     * Handle watcher.hello — first message from a new-protocol watcher.
     */
    private function handleWatcherHello(int $connId, array $data): void
    {
        $watcherId = $data['watcher_id'] ?? null;

        if ($watcherId === null || $watcherId === '') {
            $this->logger->warning('watcher.hello without watcher_id', ['id' => $connId]);
            $this->closeConnection($connId);

            return;
        }

        try {
            $watcher = $this->lifecycleService->findOrCreateWatcher($watcherId, $data['hostname'] ?? null, $data['version'] ?? null);
        } catch (Throwable $e) {
            $this->logger->error('Failed to find/create watcher', ['id' => $connId, 'watcher_id' => $watcherId, 'error' => $e->getMessage()]);
            $this->closeConnection($connId);

            return;
        }

        $this->respondToWatcherHello($connId, $watcherId, $watcher);
    }

    private function respondToWatcherHello(int $connId, string $watcherId, Watcher $watcher): void
    {
        $this->connections[$connId]['watcherId'] = $watcherId;
        $this->watcherConnections[$watcherId] = $connId;

        if ($watcher->getStatus()->value === 'revoked') {
            $this->logger->info('Revoked watcher attempted connection', ['watcher_id' => $watcherId, 'id' => $connId]);
            $this->sendToConnection($connId, ['type' => 'watcher.rejected', 'data' => ['reason' => 'revoked']]);
            $this->closeConnection($connId);

            return;
        }

        if ($watcher->getStatus()->value === 'pending') {
            $this->logger->info('Watcher pending approval', ['watcher_id' => $watcherId, 'id' => $connId]);
            $this->sendToConnection($connId, ['type' => 'watcher.pending', 'data' => ['watcher_id' => $watcherId]]);

            return;
        }

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

        $this->registerAuthenticatedWatcher($connId, $watcher);
    }

    private function registerAuthenticatedWatcher(int $connId, Watcher $watcher): void
    {
        $watcherId = $watcher->getWatcherId();
        $watcherDbId = (string)$watcher->getId();

        $this->connections[$connId]['authenticated'] = true;
        $this->connections[$connId]['watcherId'] = $watcherId;
        $this->connections[$connId]['watcherDbId'] = $watcherDbId;
        $this->watcherConnections[$watcherId] = $connId;

        $this->logger->info('Watcher authenticated', ['watcher_id' => $watcherId, 'id' => $connId]);
        $this->sendWatcherConfig($connId, $watcher);

        $this->broadcastToAllBrowsers('watcher.status_changed', [
            'id' => $watcherDbId,
            'watcher_id' => $watcherId,
            'status' => 'connected',
            'last_seen_at' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
        ]);

        $this->processPendingDeletionsOnReconnect($connId);
    }

    private function sendWatcherConfig(int $connId, Watcher $watcher): void
    {
        $config = $watcher->getConfig();
        $config['watch_paths'] = array_values(array_filter(array_map(
            static fn ($wp): mixed => is_array($wp) ? ($wp['path'] ?? '') : (string)$wp,
            $config['watch_paths'] ?? [],
        )));
        $this->sendToConnection($connId, ['type' => 'watcher.config', 'data' => array_merge($config, ['config_hash' => $watcher->getConfigHash()])]);
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
            $this->handleWatcherCleanup($connId, $watcherId, $watcherDbId);
        }
    }

    private function handleWatcherCleanup(int $connId, string $watcherId, ?string $watcherDbId): void
    {
        if (($this->watcherConnections[$watcherId] ?? null) === $connId) {
            unset($this->watcherConnections[$watcherId]);
        }

        try {
            $this->lifecycleService->handleWatcherDisconnect($watcherId);
        } catch (Throwable $e) {
            $this->logger->error('Failed to update watcher status on disconnect', ['watcher_id' => $watcherId, 'error' => $e->getMessage()]);
        }

        if ($watcherDbId !== null) {
            $this->broadcastToAllBrowsers('watcher.status_changed', [
                'id' => $watcherDbId,
                'watcher_id' => $watcherId,
                'status' => 'disconnected',
                'last_seen_at' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
            ]);
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
