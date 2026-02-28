<?php

declare(strict_types=1);

namespace App\Exception;

final class DeletionNotAllowedException extends DomainException
{
    public static function protectedMovie(string $title): self
    {
        return new self(sprintf('Movie "%s" is protected and cannot be deleted.', $title));
    }

    public static function trackerRule(string $reason): self
    {
        return new self(sprintf('Deletion blocked by tracker rule: %s', $reason));
    }
}
