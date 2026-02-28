<?php

declare(strict_types=1);

namespace App\Dto\Internal;

use App\Entity\Movie;

final readonly class MatchResult
{
    private function __construct(
        public string $status,
        public ?Movie $movie = null,
        public ?string $reason = null,
    ) {
    }

    public static function found(Movie $movie): self
    {
        return new self('found', $movie);
    }

    public static function notFound(?string $reason = null): self
    {
        return new self('not_found', reason: $reason);
    }

    public static function ambiguous(string $reason): self
    {
        return new self('ambiguous', reason: $reason);
    }

    public function isFound(): bool
    {
        return $this->status === 'found';
    }

    public function isNotFound(): bool
    {
        return $this->status === 'not_found';
    }

    public function isAmbiguous(): bool
    {
        return $this->status === 'ambiguous';
    }
}
