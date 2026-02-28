<?php

declare(strict_types=1);

namespace App\Contract\Matching;

interface FileMatchingStrategyInterface
{
    /**
     * Higher priority = tried first. Highest priority wins.
     */
    public static function getPriority(): int;

    /**
     * Try to match an external path to a known MediaFile.
     *
     * @param array<string, mixed> $context  Optional context (inode, device_id, torrent_hash, …)
     *
     * @return MatchResult|null  Null means "cannot match — try next strategy"
     */
    public function match(string $externalPath, array $context = []): ?MatchResult;
}
