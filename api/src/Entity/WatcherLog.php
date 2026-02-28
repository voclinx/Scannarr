<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WatcherLogRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: WatcherLogRepository::class)]
#[ORM\Table(name: 'watcher_logs')]
#[ORM\Index(name: 'idx_watcher_logs_watcher_created', columns: ['watcher_id', 'created_at'])]
#[ORM\Index(name: 'idx_watcher_logs_level', columns: ['level'])]
class WatcherLog
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Watcher::class, inversedBy: 'logs')]
    #[ORM\JoinColumn(name: 'watcher_id', nullable: false, onDelete: 'CASCADE')]
    private Watcher $watcher;

    #[ORM\Column(length: 10)]
    private string $level = 'info';

    #[ORM\Column(type: 'text')]
    private string $message = '';

    #[ORM\Column(type: 'json')]
    private array $context = [];

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getWatcher(): Watcher
    {
        return $this->watcher;
    }

    public function setWatcher(Watcher $watcher): static
    {
        $this->watcher = $watcher;

        return $this;
    }

    public function getLevel(): string
    {
        return $this->level;
    }

    public function setLevel(string $level): static
    {
        $this->level = $level;

        return $this;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): static
    {
        $this->message = $message;

        return $this;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function setContext(array $context): static
    {
        $this->context = $context;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}
