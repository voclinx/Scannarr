<?php

declare(strict_types=1);

namespace App\Contract\Matching;

use App\Entity\MediaFile;

final readonly class MatchResult
{
    public function __construct(
        public MediaFile $mediaFile,
        public string $strategy,
        public float $confidence,
    ) {
    }
}
