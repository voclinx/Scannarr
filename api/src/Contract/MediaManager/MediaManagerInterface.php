<?php

declare(strict_types=1);

namespace App\Contract\MediaManager;

use App\Entity\RadarrInstance;

interface MediaManagerInterface
{
    /** @return array{success: bool, message: string} */
    public function testConnection(RadarrInstance $instance): array;

    /** @return array<int, array<string, mixed>> */
    public function getAllMovies(RadarrInstance $instance): array;

    /** @return array<string, mixed> */
    public function getMovie(RadarrInstance $instance, int $radarrId): array;

    public function deleteMovie(RadarrInstance $instance, int $radarrId, bool $deleteFiles = false, bool $addExclusion = false): void;

    public function rescanMovie(RadarrInstance $instance, int $radarrMovieId): void;

    /** @return array<int, array<string, mixed>> */
    public function getHistory(RadarrInstance $instance, int $pageSize = 1000): array;
}
