<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\VolumeStatus;
use App\Enum\VolumeType;
use App\Repository\VolumeRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: VolumeRepository::class)]
#[ORM\Table(name: 'volumes')]
#[ORM\HasLifecycleCallbacks]
class Volume
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private ?string $name = null;

    #[ORM\Column(length: 500, unique: true)]
    #[Assert\NotBlank]
    private ?string $path = null;

    #[ORM\Column(length: 500)]
    #[Assert\NotBlank]
    private ?string $hostPath = null;

    #[ORM\Column(length: 20, enumType: VolumeType::class)]
    private VolumeType $type = VolumeType::LOCAL;

    #[ORM\Column(length: 20, enumType: VolumeStatus::class)]
    private VolumeStatus $status = VolumeStatus::ACTIVE;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $totalSpaceBytes = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $usedSpaceBytes = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $lastScanAt = null;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    /** @var Collection<int, MediaFile> */
    #[ORM\OneToMany(targetEntity: MediaFile::class, mappedBy: 'volume', cascade: ['remove'])]
    private Collection $mediaFiles;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
        $this->mediaFiles = new ArrayCollection();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function setPath(string $path): static
    {
        $this->path = $path;

        return $this;
    }

    public function getHostPath(): ?string
    {
        return $this->hostPath;
    }

    public function setHostPath(string $hostPath): static
    {
        $this->hostPath = $hostPath;

        return $this;
    }

    public function getType(): VolumeType
    {
        return $this->type;
    }

    public function setType(VolumeType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getStatus(): VolumeStatus
    {
        return $this->status;
    }

    public function setStatus(VolumeStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getTotalSpaceBytes(): ?int
    {
        return $this->totalSpaceBytes;
    }

    public function setTotalSpaceBytes(?int $totalSpaceBytes): static
    {
        $this->totalSpaceBytes = $totalSpaceBytes;

        return $this;
    }

    public function getUsedSpaceBytes(): ?int
    {
        return $this->usedSpaceBytes;
    }

    public function setUsedSpaceBytes(?int $usedSpaceBytes): static
    {
        $this->usedSpaceBytes = $usedSpaceBytes;

        return $this;
    }

    public function getLastScanAt(): ?DateTimeImmutable
    {
        return $this->lastScanAt;
    }

    public function setLastScanAt(?DateTimeImmutable $lastScanAt): static
    {
        $this->lastScanAt = $lastScanAt;

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

    /** @return Collection<int, MediaFile> */
    public function getMediaFiles(): Collection
    {
        return $this->mediaFiles;
    }
}
