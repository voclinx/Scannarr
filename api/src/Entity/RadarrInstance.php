<?php

namespace App\Entity;

use App\Repository\RadarrInstanceRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: RadarrInstanceRepository::class)]
#[ORM\Table(name: 'radarr_instances')]
#[ORM\HasLifecycleCallbacks]
class RadarrInstance
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private ?string $name = null;

    #[ORM\Column(length: 500)]
    #[Assert\NotBlank]
    #[Assert\Url]
    private ?string $url = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    private ?string $apiKey = null;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $rootFolders = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $lastSyncAt = null;

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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(string $url): static
    {
        $this->url = $url;

        return $this;
    }

    public function getApiKey(): ?string
    {
        return $this->apiKey;
    }

    public function setApiKey(string $apiKey): static
    {
        $this->apiKey = $apiKey;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getRootFolders(): ?array
    {
        return $this->rootFolders;
    }

    public function setRootFolders(?array $rootFolders): static
    {
        $this->rootFolders = $rootFolders;

        return $this;
    }

    public function getLastSyncAt(): ?DateTimeImmutable
    {
        return $this->lastSyncAt;
    }

    public function setLastSyncAt(?DateTimeImmutable $lastSyncAt): static
    {
        $this->lastSyncAt = $lastSyncAt;

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
