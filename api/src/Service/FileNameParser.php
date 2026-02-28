<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Parse media file names to extract metadata.
 *
 * Supported formats:
 *   Title.Year.Resolution.Quality.Codec-Group.ext
 *   Title (Year) Resolution Quality Codec-Group.ext
 *   Title.Year.Resolution.Codec.ext
 *
 * Examples:
 *   "Inception.2010.2160p.BluRay.x265-GROUP.mkv"
 *   "The.Matrix.1999.1080p.WEB-DL.x264-SCENE.mkv"
 *   "Avatar (2009) 720p BDRip x264.mkv"
 */
final class FileNameParser
{
    /** @var string[] */
    private const array RESOLUTIONS = ['2160p', '1080p', '720p', '480p', '4K', 'UHD'];

    /** @var string[] */
    private const array QUALITIES = [
        'BluRay', 'Bluray', 'BDRip', 'BRRip', 'WEB-DL', 'WEBRip', 'WEB',
        'HDTV', 'DVDRip', 'Remux', 'PROPER', 'REPACK',
    ];

    /** @var string[] */
    private const array CODECS = [
        'x264', 'x265', 'H.264', 'H264', 'H.265', 'H265',
        'HEVC', 'AVC', 'AV1', 'VP9', 'MPEG-2', 'XviD', 'DivX',
    ];

    /**
     * Parse a media file name and extract metadata.
     *
     * @return array{
     *     title: ?string,
     *     year: ?int,
     *     resolution: ?string,
     *     quality: ?string,
     *     codec: ?string
     * }
     */
    public function parse(string $fileName): array
    {
        // Remove file extension
        $name = pathinfo($fileName, PATHINFO_FILENAME);

        // Extract year (4 digits between 1900 and 2099)
        preg_match('/[\.\s\(]?((?:19|20)\d{2})[\.\s\)]?/', $name, $yearMatch);
        $year = $yearMatch[1] ?? null;

        // Extract title (everything before the year)
        $title = null;
        if ($year !== null) {
            $parts = preg_split('/[\.\s\(]?' . $year . '/', $name);
            $titlePart = $parts[0] ?? '';
            $title = str_replace(['.', '_'], ' ', trim($titlePart));
            // Clean up extra spaces
            $title = preg_replace('/\s+/', ' ', $title);
            $title = trim((string)$title);
        }

        // Extract resolution, quality, codec (case-insensitive)
        $resolution = $this->findMatch($name, self::RESOLUTIONS);
        $quality = $this->findMatch($name, self::QUALITIES);
        $codec = $this->findMatch($name, self::CODECS);

        return [
            'title' => $title ?: null,
            'year' => $year !== null ? (int)$year : null,
            'resolution' => $resolution,
            'quality' => $quality,
            'codec' => $codec,
        ];
    }

    /**
     * Find the first case-insensitive match from a list of needles.
     *
     * @param string[] $needles
     */
    private function findMatch(string $haystack, array $needles): ?string
    {
        foreach ($needles as $needle) {
            if (stripos($haystack, $needle) !== false) {
                return $needle;
            }
        }

        return null;
    }
}
