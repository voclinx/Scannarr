<?php

declare(strict_types=1);

namespace App\Matching;

use App\Contract\Matching\FileMatchingStrategyInterface;
use App\Contract\Matching\MatchResult;
use App\Repository\MediaFileRepository;

final class InodeMatchingStrategy implements FileMatchingStrategyInterface
{
    public function __construct(
        private readonly MediaFileRepository $mediaFileRepository,
    ) {
    }

    public static function getPriority(): int
    {
        return 100; // Highest priority — inode match is guaranteed (confidence 1.0)
    }

    /**
     * Match by (device_id, inode) couple from the context.
     * Returns null if inode/device_id are not provided or no matching file is found.
     *
     * @param array<string, mixed> $context
     */
    public function match(string $externalPath, array $context = []): ?MatchResult
    {
        $inode = $context['inode'] ?? null;
        $deviceId = $context['device_id'] ?? null;

        if ($inode === null || $deviceId === null) {
            return null;
        }

        $mediaFile = $this->mediaFileRepository->findByInode(
            (string) $deviceId,
            (string) $inode,
        );

        if ($mediaFile === null) {
            return null;
        }

        return new MatchResult(
            mediaFile: $mediaFile,
            strategy: 'inode',
            confidence: 1.0,
        );
    }
}
