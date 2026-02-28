<?php

declare(strict_types=1);

namespace App\Contract\TorrentClient;

interface TorrentClientInterface
{
    public function isConfigured(): bool;

    /** @return array{success: bool, message: string} */
    public function testConnection(): array;

    /** @return array<int, array<string, mixed>> */
    public function getAllTorrents(): array;

    /** @return array<int, array<string, mixed>> */
    public function getTorrentFiles(string $hash): array;

    /**
     * @param string[] $hashes
     */
    public function deleteTorrents(array $hashes, bool $deleteFiles = false): bool;
}
