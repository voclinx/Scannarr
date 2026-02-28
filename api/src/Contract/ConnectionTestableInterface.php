<?php

declare(strict_types=1);

namespace App\Contract;

interface ConnectionTestableInterface
{
    public function isConfigured(): bool;

    /** @return array{success: bool, message: string, latency?: float|null} */
    public function testConnection(mixed ...$args): array;
}
