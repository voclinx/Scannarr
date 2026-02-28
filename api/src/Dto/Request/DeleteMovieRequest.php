<?php

declare(strict_types=1);

namespace App\Dto\Request;

use Symfony\Component\HttpFoundation\Request;

final readonly class DeleteMovieRequest
{
    /**
     * @param string[] $fileIds
     * @param array<string, string> $replacementMap
     */
    public function __construct(
        public array $fileIds = [],
        public bool $deleteRadarrReference = false,
        public bool $deleteMediaPlayerReference = false,
        public bool $disableRadarrAutoSearch = false,
        public array $replacementMap = [],
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $data = json_decode($request->getContent(), true, 512, \JSON_THROW_ON_ERROR) ?? [];

        return new self(
            fileIds: $data['file_ids'] ?? [],
            deleteRadarrReference: (bool)($data['delete_radarr_reference'] ?? false),
            deleteMediaPlayerReference: (bool)($data['delete_media_player_reference'] ?? false),
            disableRadarrAutoSearch: (bool)($data['disable_radarr_auto_search'] ?? false),
            replacementMap: $data['replacement_map'] ?? [],
        );
    }
}
