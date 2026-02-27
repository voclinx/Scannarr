<?php

namespace App\Entity;

use App\Enum\DeletionStatus;
use App\Repository\ScheduledDeletionRepository;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ScheduledDeletionRepository::class)]
#[ORM\Table(name: 'scheduled_deletions')]
#[ORM\HasLifecycleCallbacks]
class ScheduledDeletion
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $createdBy = null;

    #[ORM\Column(type: 'date')]
    private ?DateTimeInterface $scheduledDate = null;

    #[ORM\Column(type: 'time')]
    private DateTimeInterface $executionTime;

    #[ORM\Column(length: 20, enumType: DeletionStatus::class)]
    private DeletionStatus $status = DeletionStatus::PENDING;

    #[ORM\Column]
    private bool $deletePhysicalFiles = true;

    #[ORM\Column]
    private bool $deleteRadarrReference = false;

    #[ORM\Column]
    private bool $deleteMediaPlayerReference = false;

    #[ORM\Column]
    private bool $disableRadarrAutoSearch = false;

    #[ORM\Column(nullable: true)]
    private ?int $reminderDaysBefore = 3;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $reminderSentAt = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $executedAt = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $executionReport = null;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    /** @var Collection<int, ScheduledDeletionItem> */
    #[ORM\OneToMany(targetEntity: ScheduledDeletionItem::class, mappedBy: 'scheduledDeletion', cascade: ['persist', 'remove'])]
    private Collection $items;

    public function __construct()
    {
        $this->executionTime = new DateTime('23:59:00');
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
        $this->items = new ArrayCollection();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getScheduledDate(): ?DateTimeInterface
    {
        return $this->scheduledDate;
    }

    public function setScheduledDate(DateTimeInterface $scheduledDate): static
    {
        $this->scheduledDate = $scheduledDate;

        return $this;
    }

    public function getExecutionTime(): DateTimeInterface
    {
        return $this->executionTime;
    }

    public function setExecutionTime(DateTimeInterface $executionTime): static
    {
        $this->executionTime = $executionTime;

        return $this;
    }

    public function getStatus(): DeletionStatus
    {
        return $this->status;
    }

    public function setStatus(DeletionStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isDeletePhysicalFiles(): bool
    {
        return $this->deletePhysicalFiles;
    }

    public function setDeletePhysicalFiles(bool $deletePhysicalFiles): static
    {
        $this->deletePhysicalFiles = $deletePhysicalFiles;

        return $this;
    }

    public function isDeleteRadarrReference(): bool
    {
        return $this->deleteRadarrReference;
    }

    public function setDeleteRadarrReference(bool $deleteRadarrReference): static
    {
        $this->deleteRadarrReference = $deleteRadarrReference;

        return $this;
    }

    public function isDeleteMediaPlayerReference(): bool
    {
        return $this->deleteMediaPlayerReference;
    }

    public function setDeleteMediaPlayerReference(bool $deleteMediaPlayerReference): static
    {
        $this->deleteMediaPlayerReference = $deleteMediaPlayerReference;

        return $this;
    }

    public function isDisableRadarrAutoSearch(): bool
    {
        return $this->disableRadarrAutoSearch;
    }

    public function setDisableRadarrAutoSearch(bool $disableRadarrAutoSearch): static
    {
        $this->disableRadarrAutoSearch = $disableRadarrAutoSearch;

        return $this;
    }

    public function getReminderDaysBefore(): ?int
    {
        return $this->reminderDaysBefore;
    }

    public function setReminderDaysBefore(?int $reminderDaysBefore): static
    {
        $this->reminderDaysBefore = $reminderDaysBefore;

        return $this;
    }

    public function getReminderSentAt(): ?DateTimeImmutable
    {
        return $this->reminderSentAt;
    }

    public function setReminderSentAt(?DateTimeImmutable $reminderSentAt): static
    {
        $this->reminderSentAt = $reminderSentAt;

        return $this;
    }

    public function getExecutedAt(): ?DateTimeImmutable
    {
        return $this->executedAt;
    }

    public function setExecutedAt(?DateTimeImmutable $executedAt): static
    {
        $this->executedAt = $executedAt;

        return $this;
    }

    public function getExecutionReport(): ?array
    {
        return $this->executionReport;
    }

    public function setExecutionReport(?array $executionReport): static
    {
        $this->executionReport = $executionReport;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    /** @return Collection<int, ScheduledDeletionItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(ScheduledDeletionItem $item): static
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setScheduledDeletion($this);
        }

        return $this;
    }
}
