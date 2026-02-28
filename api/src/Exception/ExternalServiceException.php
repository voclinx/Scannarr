<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;
use Throwable;

final class ExternalServiceException extends RuntimeException
{
    public static function radarr(string $reason, ?Throwable $previous = null): self
    {
        return new self(sprintf('Radarr error: %s', $reason), 0, $previous);
    }

    public static function plex(string $reason, ?Throwable $previous = null): self
    {
        return new self(sprintf('Plex error: %s', $reason), 0, $previous);
    }

    public static function jellyfin(string $reason, ?Throwable $previous = null): self
    {
        return new self(sprintf('Jellyfin error: %s', $reason), 0, $previous);
    }

    public static function qbittorrent(string $reason, ?Throwable $previous = null): self
    {
        return new self(sprintf('qBittorrent error: %s', $reason), 0, $previous);
    }

    public static function tmdb(string $reason, ?Throwable $previous = null): self
    {
        return new self(sprintf('TMDB error: %s', $reason), 0, $previous);
    }

    public static function discord(string $reason, ?Throwable $previous = null): self
    {
        return new self(sprintf('Discord error: %s', $reason), 0, $previous);
    }
}
