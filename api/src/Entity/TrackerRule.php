<?php

namespace App\Entity;

use App\Repository\TrackerRuleRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TrackerRuleRepository::class)]
#[ORM\Table(name: 'tracker_rules')]
#[ORM\UniqueConstraint(name: 'uniq_tracker_domain', columns: ['tracker_domain'])]
#[ORM\HasLifecycleCallbacks]
class TrackerRule
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(length: 255)]
    private ?string $trackerDomain = null;

    #[ORM\Column]
    private int $minSeedTimeHours = 0;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 4)]
    private string $minRatio = '0.0000';

    #[ORM\Column]
    private bool $isAutoDetected = true;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getTrackerDomain(): ?string
    {
        return $this->trackerDomain;
    }

    public function setTrackerDomain(string $trackerDomain): static
    {
        $this->trackerDomain = $trackerDomain;

        return $this;
    }

    public function getMinSeedTimeHours(): int
    {
        return $this->minSeedTimeHours;
    }

    public function setMinSeedTimeHours(int $minSeedTimeHours): static
    {
        $this->minSeedTimeHours = $minSeedTimeHours;

        return $this;
    }

    public function getMinRatio(): string
    {
        return $this->minRatio;
    }

    public function setMinRatio(string $minRatio): static
    {
        $this->minRatio = $minRatio;

        return $this;
    }

    public function isAutoDetected(): bool
    {
        return $this->isAutoDetected;
    }

    public function setIsAutoDetected(bool $isAutoDetected): static
    {
        $this->isAutoDetected = $isAutoDetected;

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
}
