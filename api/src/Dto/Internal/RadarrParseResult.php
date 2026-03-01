<?php

declare(strict_types=1);

namespace App\Dto\Internal;

/**
 * DTO for Radarr parse API response.
 *
 * Maps the response from GET /api/v3/parse?title=... into a typed object.
 */
final readonly class RadarrParseResult
{
    private const array QUALITY_MAP = [
        'remux' => 'Remux',
        'bluray' => 'BluRay',
        'bdrip' => 'BluRay',
        'brrip' => 'BluRay',
        'webdl' => 'WEB-DL',
        'web-dl' => 'WEB-DL',
        'webrip' => 'WEBRip',
        'hdtv' => 'HDTV',
        'dvd' => 'DVD',
    ];

    /**
     * @param string[] $titles Parsed movie title variations
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        public array $titles,
        public ?int $year,
        public ?string $quality,
        public ?string $resolution,
        public ?string $codec,
        public ?int $tmdbId,
    ) {
    }

    /**
     * Build from raw Radarr API response.
     *
     * @param array<string, mixed> $data
     */
    public static function fromRadarrResponse(array $data): self
    {
        $info = $data['parsedMovieInfo'] ?? [];
        $movie = $data['movie'] ?? null;

        $qualityInfo = $info['quality']['quality'] ?? [];
        $qualityName = $qualityInfo['name'] ?? null;

        $resolution = self::extractResolution($qualityName, $qualityInfo);
        $quality = $qualityName !== null ? self::normalizeQuality($qualityName) : null;

        return new self(
            titles: $info['movieTitles'] ?? [],
            year: isset($info['year']) && $info['year'] > 0 ? (int)$info['year'] : null,
            quality: $quality,
            resolution: $resolution,
            codec: null,
            tmdbId: $movie['tmdbId'] ?? null,
        );
    }

    public function hasMovie(): bool
    {
        return $this->tmdbId !== null;
    }

    public function getPrimaryTitle(): ?string
    {
        return $this->titles[0] ?? null;
    }

    /**
     * Extract resolution from quality name or quality info.
     *
     * @param array<string, mixed> $qualityInfo
     */
    private static function extractResolution(?string $qualityName, array $qualityInfo): ?string
    {
        if ($qualityName !== null && preg_match('/(\d{3,4}p)/i', $qualityName, $matches)) {
            return $matches[1];
        }

        return $qualityInfo['resolution'] ?? null;
    }

    /**
     * Normalize Radarr quality name to a simpler form.
     *
     * Examples: "Bluray-1080p" -> "BluRay", "WEBDL-2160p" -> "WEB-DL", "DVD-R" -> "DVD"
     */
    private static function normalizeQuality(string $qualityName): string
    {
        $lower = strtolower($qualityName);

        foreach (self::QUALITY_MAP as $keyword => $normalized) {
            if (str_contains($lower, $keyword)) {
                return $normalized;
            }
        }

        return $qualityName;
    }
}
