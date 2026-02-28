<?php

declare(strict_types=1);

namespace App\Dto\Internal;

final readonly class ConnectionTestResult
{
    public function __construct(
        public bool $success,
        public string $message,
        public ?float $latency = null,
    ) {
    }

    public static function success(string $message, ?float $latency = null): self
    {
        return new self(true, $message, $latency);
    }

    public static function failure(string $message): self
    {
        return new self(false, $message);
    }

    /** @return array{success: bool, message: string, latency?: float|null} */
    public function toArray(): array
    {
        $data = ['success' => $this->success, 'message' => $this->message];
        if ($this->latency !== null) {
            $data['latency'] = $this->latency;
        }

        return $data;
    }
}
