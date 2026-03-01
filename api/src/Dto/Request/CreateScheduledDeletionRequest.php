<?php

declare(strict_types=1);

namespace App\Dto\Request;

use Symfony\Component\HttpFoundation\Request;

final readonly class CreateScheduledDeletionRequest
{
    /**
     * @param array<int, array{movie_id: string, file_ids: string[]}> $items
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        public string $scheduledDate,
        public array $items = [],
        public bool $deletePhysicalFiles = true,
        public bool $deleteRadarrReference = false,
        public bool $deleteMediaPlayerReference = false,
        public bool $disableRadarrAutoSearch = false,
        public ?string $presetId = null,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $data = json_decode($request->getContent(), true, 512, \JSON_THROW_ON_ERROR) ?? [];

        return new self(
            scheduledDate: $data['scheduled_date'] ?? '',
            items: $data['items'] ?? [],
            deletePhysicalFiles: (bool)($data['delete_physical_files'] ?? true),
            deleteRadarrReference: (bool)($data['delete_radarr_reference'] ?? false),
            deleteMediaPlayerReference: (bool)($data['delete_media_player_reference'] ?? false),
            disableRadarrAutoSearch: (bool)($data['disable_radarr_auto_search'] ?? false),
            presetId: $data['preset_id'] ?? null,
        );
    }
}
