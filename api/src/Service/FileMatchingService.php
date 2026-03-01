<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\Matching\FileMatchingStrategyInterface;
use App\Contract\Matching\MatchResult;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

final readonly class FileMatchingService
{
    /** @var list<FileMatchingStrategyInterface> */
    private array $strategies;

    /**
     * @param iterable<FileMatchingStrategyInterface> $strategies
     */
    public function __construct(
        #[TaggedIterator('scanarr.matching_strategy', defaultPriorityMethod: 'getPriority')]
        iterable $strategies,
    ) {
        // Sort by descending priority (highest first)
        $arr = iterator_to_array($strategies);
        usort($arr, static fn ($stratA, $stratB): int => $stratB::getPriority() <=> $stratA::getPriority());
        $this->strategies = $arr;
    }

    /**
     * Try each strategy in priority order. Return the first match, or null.
     *
     * @param array<string, mixed> $context
     */
    public function match(string $externalPath, array $context = []): ?MatchResult
    {
        foreach ($this->strategies as $strategy) {
            $result = $strategy->match($externalPath, $context);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }
}
