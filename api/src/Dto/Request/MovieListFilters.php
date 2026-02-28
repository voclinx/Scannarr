<?php

declare(strict_types=1);

namespace App\Dto\Request;

use Symfony\Component\HttpFoundation\Request;

final readonly class MovieListFilters
{
    public function __construct(
        public ?string $search = null,
        public string $sort = 'title',
        public string $order = 'ASC',
        public int $page = 1,
        public int $limit = 25,
        public ?string $radarrInstanceId = null,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            search: $request->query->get('search'),
            sort: $request->query->get('sort', 'title'),
            order: $request->query->get('order', 'ASC'),
            page: $request->query->getInt('page', 1),
            limit: $request->query->getInt('limit', 25),
            radarrInstanceId: $request->query->get('radarr_instance_id'),
        );
    }

    /** @return array<string, mixed> */
    public function toRepositoryFilters(): array
    {
        return [
            'search' => $this->search,
            'sort' => $this->sort,
            'order' => $this->order,
            'page' => $this->page,
            'limit' => $this->limit,
            'radarr_instance_id' => $this->radarrInstanceId,
        ];
    }
}
