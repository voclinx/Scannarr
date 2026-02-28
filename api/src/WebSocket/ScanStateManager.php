<?php

declare(strict_types=1);

namespace App\WebSocket;

use App\Entity\Volume;

/**
 * Holds mutable scan state across multiple watcher messages within the same process.
 * Services are singletons in Symfony, so this state persists for the lifetime of the WebSocket server.
 */
final class ScanStateManager
{
    public const int SCAN_BATCH_SIZE = 50;

    /** @var array<string, array{volume: Volume, seenPaths: array<string, true>}> */
    private array $activeScans = [];

    /** @var array<string, int> */
    private array $scanBatchCounters = [];

    public function startScan(string $scanId, Volume $volume): void
    {
        $this->activeScans[$scanId] = [
            'volume' => $volume,
            'seenPaths' => [],
        ];
        $this->scanBatchCounters[$scanId] = 0;
    }

    public function hasScan(string $scanId): bool
    {
        return isset($this->activeScans[$scanId]);
    }

    public function getVolume(string $scanId): ?Volume
    {
        return $this->activeScans[$scanId]['volume'] ?? null;
    }

    /** @return array<string, true> */
    public function getSeenPaths(string $scanId): array
    {
        return $this->activeScans[$scanId]['seenPaths'] ?? [];
    }

    public function markPathSeen(string $scanId, string $relativePath): void
    {
        $this->activeScans[$scanId]['seenPaths'][$relativePath] = true;
    }

    public function updateVolume(string $scanId, Volume $volume): void
    {
        $this->activeScans[$scanId]['volume'] = $volume;
    }

    /**
     * Increment batch counter and return the new value.
     */
    public function incrementBatch(string $scanId): int
    {
        $this->scanBatchCounters[$scanId] = ($this->scanBatchCounters[$scanId] ?? 0) + 1;

        return $this->scanBatchCounters[$scanId];
    }

    public function resetBatch(string $scanId): void
    {
        $this->scanBatchCounters[$scanId] = 0;
    }

    public function endScan(string $scanId): void
    {
        unset($this->activeScans[$scanId], $this->scanBatchCounters[$scanId]);
    }
}
