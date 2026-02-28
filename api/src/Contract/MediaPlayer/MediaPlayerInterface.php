<?php

declare(strict_types=1);

namespace App\Contract\MediaPlayer;

use App\Entity\MediaPlayerInstance;

interface MediaPlayerInterface
{
    /** @return array{success: bool, message: string} */
    public function testConnection(MediaPlayerInstance $instance): array;

    /**
     * Refresh the media library for this instance.
     * Implementations handle all necessary sub-steps (e.g., section discovery for Plex).
     */
    public function refreshLibrary(MediaPlayerInstance $instance): bool;

    /**
     * Return true if this implementation handles the given instance type.
     */
    public function supports(MediaPlayerInstance $instance): bool;
}
