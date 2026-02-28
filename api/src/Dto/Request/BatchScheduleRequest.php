<?php

declare(strict_types=1);

namespace App\Dto\Request;

use Symfony\Component\HttpFoundation\Request;

final readonly class BatchScheduleRequest
{
    /**
     * @param string[] $suggestionIds
     */
    public function __construct(
        public array $suggestionIds = [],
        public string $scheduledDate = '',
        public bool $deleteRadarrReference = false,
        public bool $deleteMediaPlayerReference = false,
        public ?string $presetId = null,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $data = json_decode($request->getContent(), true, 512, \JSON_THROW_ON_ERROR) ?? [];

        return new self(
            suggestionIds: $data['suggestion_ids'] ?? [],
            scheduledDate: $data['scheduled_date'] ?? '',
            deleteRadarrReference: (bool)($data['delete_radarr_reference'] ?? false),
            deleteMediaPlayerReference: (bool)($data['delete_media_player_reference'] ?? false),
            presetId: $data['preset_id'] ?? null,
        );
    }
}
