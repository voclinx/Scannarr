<?php

declare(strict_types=1);

namespace App\Dto\Response;

final readonly class PaginatedResponse
{
    /**
     * @param array<int, mixed> $data
     */
    public function __construct(
        public array $data,
        public int $total,
        public int $page,
        public int $limit,
        public int $totalPages,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'data' => $this->data,
            'meta' => [
                'total' => $this->total,
                'page' => $this->page,
                'limit' => $this->limit,
                'total_pages' => $this->totalPages,
            ],
        ];
    }
}
