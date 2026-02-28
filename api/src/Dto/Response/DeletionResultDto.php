<?php

declare(strict_types=1);

namespace App\Dto\Response;

use App\Entity\ScheduledDeletion;
use App\Enum\DeletionStatus;

final readonly class DeletionResultDto
{
    public function __construct(
        public string $deletionId,
        public string $status,
        public int $filesCount,
        public ?string $message = null,
        public ?string $warning = null,
        /** @var string[]|null */
        public ?array $invalidIds = null,
    ) {
    }

    public static function fromDeletion(
        ScheduledDeletion $deletion,
        int $filesCount = 0,
        ?string $warning = null,
    ): self {
        return new self(
            deletionId: (string)$deletion->getId(),
            status: $deletion->getStatus()->value,
            filesCount: $filesCount,
            message: 'Deletion initiated',
            warning: $warning,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'message' => $this->message ?? 'Deletion initiated',
            'deletion_id' => $this->deletionId,
            'status' => $this->status,
            'files_count' => $this->filesCount,
        ];

        if ($this->warning !== null) {
            $data['warning'] = $this->warning;
        }

        return $data;
    }

    public function httpStatus(): int
    {
        $status = DeletionStatus::from($this->status);

        return match ($status) {
            DeletionStatus::EXECUTING, DeletionStatus::WAITING_WATCHER => 202,
            default => 200,
        };
    }
}
