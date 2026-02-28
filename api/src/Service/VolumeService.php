<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Volume;
use App\Enum\VolumeStatus;
use App\Repository\MediaFileRepository;
use App\Repository\VolumeRepository;

final class VolumeService
{
    public function __construct(
        private readonly VolumeRepository $volumeRepository,
        private readonly MediaFileRepository $mediaFileRepository,
        private readonly WatcherCommandService $watcherCommandService,
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function list(): array
    {
        $volumes = $this->volumeRepository->findBy([], ['name' => 'ASC']);

        return array_map($this->serialize(...), $volumes);
    }

    /**
     * Trigger a scan for a volume.
     *
     * @return array{status: string, code: int, scan_id?: string, message?: string}
     */
    public function scan(string $id): array
    {
        $volume = $this->volumeRepository->find($id);
        if ($volume === null) {
            return ['status' => 'not_found', 'code' => 404];
        }

        if ($volume->getStatus() !== VolumeStatus::ACTIVE) {
            return ['status' => 'inactive', 'code' => 422, 'message' => 'Cannot scan an inactive or errored volume'];
        }

        $scanId = $this->watcherCommandService->requestScan($volume->getHostPath());

        return [
            'status' => 'accepted',
            'code' => 202,
            'scan_id' => $scanId,
            'message' => "Scan initiated for volume '{$volume->getName()}'",
            'volume_id' => (string)$volume->getId(),
        ];
    }

    /** @return array<string, mixed> */
    private function serialize(Volume $volume): array
    {
        return [
            'id' => (string)$volume->getId(),
            'name' => $volume->getName(),
            'path' => $volume->getPath(),
            'host_path' => $volume->getHostPath(),
            'type' => $volume->getType()->value,
            'status' => $volume->getStatus()->value,
            'total_space_bytes' => $volume->getTotalSpaceBytes(),
            'used_space_bytes' => $volume->getUsedSpaceBytes(),
            'last_scan_at' => $volume->getLastScanAt()?->format('c'),
            'file_count' => $this->mediaFileRepository->countByVolume($volume),
            'created_at' => $volume->getCreatedAt()->format('c'),
            'updated_at' => $volume->getUpdatedAt()->format('c'),
        ];
    }
}
