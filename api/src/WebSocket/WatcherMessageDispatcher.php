<?php

declare(strict_types=1);

namespace App\WebSocket;

use App\Contract\WebSocket\WatcherMessageHandlerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Throwable;

/**
 * Dispatches incoming watcher WebSocket messages to the appropriate handler.
 * Replaces the monolithic WatcherMessageProcessor::process() method.
 */
final class WatcherMessageDispatcher
{
    /** @param WatcherMessageHandlerInterface[] $handlers */
    public function __construct(
        #[TaggedIterator('scanarr.watcher_message_handler')]
        private readonly iterable $handlers,
        private EntityManagerInterface $em,
        private readonly ManagerRegistry $managerRegistry,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Dispatch a decoded message from the watcher to the appropriate handler.
     *
     * @param array<string, mixed> $message
     */
    public function dispatch(array $message): void
    {
        $type = $message['type'] ?? 'unknown';

        $this->logger->info('Processing watcher message', ['type' => $type]);

        // Ensure the EntityManager is open (long-running process safety)
        if (!$this->em->isOpen()) {
            $this->logger->warning('EntityManager was closed, resetting');
            $this->managerRegistry->resetManager();
            $this->em = $this->managerRegistry->getManager();
        }

        try {
            foreach ($this->handlers as $handler) {
                if ($handler->supports($type)) {
                    $handler->handle($message);

                    return;
                }
            }

            $this->logger->warning('Unknown message type — no handler found', ['type' => $type]);
        } catch (Throwable $e) {
            $this->logger->error('Error processing message', [
                'type' => $type,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->em->clear();
        }
    }
}
