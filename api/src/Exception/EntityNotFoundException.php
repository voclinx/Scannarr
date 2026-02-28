<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;
use Symfony\Component\Uid\Uuid;

final class EntityNotFoundException extends RuntimeException
{
    public static function movie(Uuid|string $id): self
    {
        return new self(sprintf('Movie "%s" not found.', $id));
    }

    public static function mediaFile(Uuid|string $id): self
    {
        return new self(sprintf('MediaFile "%s" not found.', $id));
    }

    public static function watcher(string $id): self
    {
        return new self(sprintf('Watcher "%s" not found.', $id));
    }

    public static function scheduledDeletion(Uuid|string $id): self
    {
        return new self(sprintf('ScheduledDeletion "%s" not found.', $id));
    }

    public static function deletionPreset(Uuid|string $id): self
    {
        return new self(sprintf('DeletionPreset "%s" not found.', $id));
    }

    public static function entity(string $entityClass, Uuid|string $id): self
    {
        $short = substr($entityClass, strrpos($entityClass, '\\') + 1);

        return new self(sprintf('%s "%s" not found.', $short, $id));
    }
}
