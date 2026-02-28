<?php

declare(strict_types=1);

namespace App\Dto\Internal;

use Symfony\Component\HttpFoundation\Request;

final readonly class DeletionOptions
{
    /**
     * @param string[] $fileIds
     * @param array<string, string> $replacementMap
     */
    public function __construct(
        public array $fileIds = [],
        public bool $deleteRadarrReference = false,
        public array $replacementMap = [],
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $data = json_decode($request->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return new self(
            fileIds: $data['file_ids'] ?? [],
            deleteRadarrReference: $data['delete_radarr_reference'] ?? false,
            replacementMap: $data['replacement_map'] ?? [],
        );
    }
}
