<?php

namespace App\Entity;

use App\Enum\WatcherStatus;
use App\Repository\WatcherRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: WatcherRepository::class)]
#[ORM\Table(name: 'watchers')]
#[ORM\HasLifecycleCallbacks]
class Watcher
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    /** Unique identifier sent by the watcher itself (stable, e.g. hostname+UUID). */
    #[ORM\Column(length: 255, unique: true)]
    private string $watcherId;

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(length: 20, enumType: WatcherStatus::class)]
    private WatcherStatus $status = WatcherStatus::PENDING;

    /** Auth token generated on approval. Null until approved. */
    #[ORM\Column(length: 64, unique: true, nullable: true)]
    private ?string $authToken = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $hostname = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $version = null;

    /** Runtime configuration sent to the watcher on connection. */
    #[ORM\Column(type: 'json')]
    private array $config = [];

    /** Hash of the current config, used to detect changes. */
    #[ORM\Column(length: 64)]
    private string $configHash = '';

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $lastSeenAt = null;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    /** @var Collection<int, WatcherLog> */
    #[ORM\OneToMany(targetEntity: WatcherLog::class, mappedBy: 'watcher', cascade: ['remove'])]
    private Collection $logs;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
        $this->logs = new ArrayCollection();
        $this->config = $this->defaultConfig();
        $this->configHash = $this->computeConfigHash();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getWatcherId(): string
    {
        return $this->watcherId;
    }

    public function setWatcherId(string $watcherId): static
    {
        $this->watcherId = $watcherId;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getStatus(): WatcherStatus
    {
        return $this->status;
    }

    public function setStatus(WatcherStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getAuthToken(): ?string
    {
        return $this->authToken;
    }

    public function setAuthToken(?string $authToken): static
    {
        $this->authToken = $authToken;

        return $this;
    }

    public function getHostname(): ?string
    {
        return $this->hostname;
    }

    public function setHostname(?string $hostname): static
    {
        $this->hostname = $hostname;

        return $this;
    }

    public function getVersion(): ?string
    {
        return $this->version;
    }

    public function setVersion(?string $version): static
    {
        $this->version = $version;

        return $this;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function setConfig(array $config): static
    {
        $this->config = $config;
        $this->configHash = $this->computeConfigHash();

        return $this;
    }

    public function mergeConfig(array $partial): static
    {
        $this->config = array_merge($this->config, $partial);
        $this->configHash = $this->computeConfigHash();

        return $this;
    }

    public function getConfigHash(): string
    {
        return $this->configHash;
    }

    public function getLastSeenAt(): ?DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function setLastSeenAt(?DateTimeImmutable $lastSeenAt): static
    {
        $this->lastSeenAt = $lastSeenAt;

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

    /** @return Collection<int, WatcherLog> */
    public function getLogs(): Collection
    {
        return $this->logs;
    }

    /**
     * Compute a stable hash of the current config for change detection.
     */
    public function computeConfigHash(): string
    {
        $normalized = $this->config;
        ksort($normalized);

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    /**
     * Default runtime config sent to new watchers.
     */
    public function defaultConfig(): array
    {
        return [
            'watch_paths' => [],
            'scan_on_start' => true,
            'log_level' => 'info',
            'reconnect_delay' => '5s',
            'ping_interval' => '30s',
            'log_retention_days' => 30,
            'debug_log_retention_hours' => 24,
        ];
    }
}
