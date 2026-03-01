<?php

declare(strict_types=1);

namespace App\Matching;

use App\Contract\Matching\FileMatchingStrategyInterface;
use App\Contract\Matching\MatchResult;
use App\Entity\MediaFile;
use App\Repository\MediaFileRepository;

/**
 * Match an external path (Radarr, qBittorrent, …) to a MediaFile
 * by progressively stripping leading path segments and searching
 * for a unique "ends-with" match in the database.
 *
 * Rules:
 *  1. Generate suffixes from longest to shortest.
 *  2. Minimum 2 path segments (never match on basename alone).
 *  3. LIKE '%{suffix}' search (ends-with):
 *       0 results → try shorter suffix
 *       1 result  → MATCH
 *       2+ results → ambiguous, skip to next suffix
 *  4. Binary decision, no confidence scoring (always 1.0).
 */
final readonly class PathSuffixMatchingStrategy implements FileMatchingStrategyInterface
{
    private const int MIN_SEGMENTS = 2;

    public function __construct(
        private MediaFileRepository $mediaFileRepository,
    ) {
    }

    public static function getPriority(): int
    {
        return 50; // Below InodeMatchingStrategy (100)
    }

    /**
     * @param array<string, mixed> $context
     */
    public function match(string $externalPath, array $context = []): ?MatchResult
    {
        $suffixes = $this->generateSuffixes($externalPath);

        foreach ($suffixes as $suffix) {
            $results = $this->mediaFileRepository->findByFilePathEndsWith($suffix);

            if (count($results) === 1) {
                return new MatchResult(
                    mediaFile: $results[0],
                    strategy: 'path_suffix',
                    confidence: 1.0,
                );
            }

            // 0 results → try shorter suffix
            // 2+ results → ambiguous, skip to next suffix
        }

        return null;
    }

    /**
     * Generate path suffixes from longest to shortest, respecting MIN_SEGMENTS.
     *
     * Example: "/data/media/movies/Movie (2024)/file.mkv"
     *  → "data/media/movies/Movie (2024)/file.mkv"
     *  → "media/movies/Movie (2024)/file.mkv"
     *  → "movies/Movie (2024)/file.mkv"
     *  → "Movie (2024)/file.mkv"  (2 segments — minimum)
     *  (basename "file.mkv" alone is NOT generated)
     *
     * @return list<string>
     */
    private function generateSuffixes(string $path): array
    {
        $segments = explode('/', ltrim($path, '/'));

        if (count($segments) < self::MIN_SEGMENTS) {
            return [];
        }

        $suffixes = [];

        // Start from index 0 (full path minus leading slash) down to (count - MIN_SEGMENTS)
        $maxStartIndex = count($segments) - self::MIN_SEGMENTS;

        for ($i = 0; $i <= $maxStartIndex; $i++) {
            $suffixes[] = implode('/', array_slice($segments, $i));
        }

        return $suffixes;
    }
}
