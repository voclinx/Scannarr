<?php

declare(strict_types=1);

namespace App\Contract\Metadata;

interface MetadataProviderInterface
{
    /** @return array<string, mixed>|null */
    public function getMovieDetails(int $tmdbId, string $language = 'fr-FR'): ?array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchMovie(string $title, ?int $year = null, string $language = 'fr-FR'): array;
}
