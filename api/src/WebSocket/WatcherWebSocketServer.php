<?php

namespace App\WebSocket;

use Exception;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Message;
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

class WatcherWebSocketServer
{
    /** @var array<int, array{conn: ConnectionInterface, buffer: MessageBuffer, authenticated: bool}> */
    private array $connections = [];
    private array $authenticatedConnections = [];

    public function __construct(private readonly string $authToken, private readonly LoggerInterface $logger, private readonly WatcherMessageProcessor $messageProcessor)
    {
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
                    $conn->write("HTTP/1.1 400 Bad Request\r\nContent-Length: 0\r\n\r\n");
                    $conn->close();

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

                // WebSocket upgrade path
                if ($path !== '/ws/watcher') {
                    $conn->write("HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\n\r\n");
                    $conn->close();

                    return;
                }

                // WebSocket handshake
                $response = $negotiator->handshake($request);

                if ($response->getStatusCode() !== 101) {
                    $statusCode = $response->getStatusCode();
                    $conn->write("HTTP/1.1 {$statusCode} Error\r\nContent-Length: 0\r\n\r\n");
                    $conn->close();

                    return;
                }

                // Send 101 Switching Protocols response
                $responseStr = Message::toString($response);
                $conn->write($responseStr);

                $this->logger->info('WebSocket handshake complete', ['id' => $connId]);

                // Phase 2: WebSocket message handling
                $this->setupWebSocketConnection($connId, $conn);
            });

            // Auth timeout: 10 seconds to complete handshake + auth
            Loop::addTimer(10.0, function () use ($connId): void {
                if (isset($this->connections[$connId]) && !$this->connections[$connId]['authenticated']) {
                    $this->logger->warning('Connection auth timeout', ['id' => $connId]);
                    $this->closeConnection($connId);
                }
            });
        });

        $this->logger->info("WebSocket server started on {$host}:{$port}");
        echo "WebSocket server listening on ws://{$host}:{$port}/ws/watcher\n";
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
            $conn->write("HTTP/1.1 400 Bad Request\r\nContent-Type: application/json\r\nContent-Length: " . strlen($response) . "\r\n\r\n" . $response);
            $conn->close();

            return;
        }

        $data = json_decode($body, true);
        if (!$data) {
            $response = '{"error":"Invalid JSON"}';
            $conn->write("HTTP/1.1 400 Bad Request\r\nContent-Type: application/json\r\nContent-Length: " . strlen($response) . "\r\n\r\n" . $response);
            $conn->close();

            return;
        }

        // Verify internal auth token
        $token = $request->getHeaderLine('X-Internal-Token');
        if ($token !== $this->authToken) {
            $response = '{"error":"Unauthorized"}';
            $conn->write("HTTP/1.1 401 Unauthorized\r\nContent-Type: application/json\r\nContent-Length: " . strlen($response) . "\r\n\r\n" . $response);
            $conn->close();

            return;
        }

        $connectedWatchers = count($this->authenticatedConnections);

        if ($connectedWatchers === 0) {
            $response = '{"error":"No watcher connected"}';
            $conn->write("HTTP/1.1 503 Service Unavailable\r\nContent-Type: application/json\r\nContent-Length: " . strlen($response) . "\r\n\r\n" . $response);
            $conn->close();

            return;
        }

        // Send to all connected watchers
        $this->sendToWatcher($body);

        $response = json_encode(['ok' => true, 'watchers' => $connectedWatchers]);
        $conn->write("HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($response) . "\r\n\r\n" . $response);
        $conn->close();

        $this->logger->info('Internal command sent to watcher', ['type' => $data['type'] ?? 'unknown']);
    }

    /**
     * Handle internal GET status request.
     */
    private function handleInternalStatus(ConnectionInterface $conn): void
    {
        $response = json_encode([
            'connected_watchers' => count($this->authenticatedConnections),
            'total_connections' => count($this->connections),
        ]);
        $conn->write("HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($response) . "\r\n\r\n" . $response);
        $conn->close();
    }

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
     * Send a command to the watcher (if connected and authenticated).
     */
    public function sendToWatcher(string $json): void
    {
        foreach (array_keys($this->authenticatedConnections) as $connId) {
            if (isset($this->connections[$connId])) {
                $frame = new Frame($json, true, Frame::OP_TEXT);
                $this->connections[$connId]['conn']->write($frame->getContents());
            }
        }
    }

    private function handleMessage(int $connId, string $payload): void
    {
        $data = json_decode($payload, true);
        if (!$data || !isset($data['type'])) {
            $this->logger->warning('Invalid message format', ['id' => $connId]);

            return;
        }

        // Handle auth message
        if ($data['type'] === 'auth') {
            $token = $data['data']['token'] ?? '';
            if ($token === $this->authToken) {
                $this->connections[$connId]['authenticated'] = true;
                $this->authenticatedConnections[$connId] = true;
                $this->logger->info('Watcher authenticated', ['id' => $connId]);
            } else {
                $this->logger->warning('Invalid auth token', ['id' => $connId]);
                $this->closeConnection($connId);
            }

            return;
        }

        // Reject unauthenticated messages
        if (!($this->connections[$connId]['authenticated'] ?? false)) {
            $this->logger->warning('Message from unauthenticated connection', ['id' => $connId, 'type' => $data['type']]);

            return;
        }

        // Delegate to message processor
        try {
            $this->messageProcessor->process($data);
        } catch (Throwable $e) {
            $this->logger->error('Message processing error', [
                'type' => $data['type'],
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function handleClose(int $connId): void
    {
        $this->logger->info('Connection closed', ['id' => $connId]);
        unset($this->connections[$connId]);
        unset($this->authenticatedConnections[$connId]);
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
