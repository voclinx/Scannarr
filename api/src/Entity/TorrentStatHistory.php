<?php

namespace App\Entity;

use App\Repository\TorrentStatHistoryRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TorrentStatHistoryRepository::class)]
#[ORM\Table(name: 'torrent_stats_history')]
#[ORM\Index(name: 'idx_torrent_history_stats', columns: ['torrent_stat_id'])]
#[ORM\Index(name: 'idx_torrent_history_date', columns: ['recorded_at'])]
class TorrentStatHistory
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: TorrentStat::class, inversedBy: 'history')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?TorrentStat $torrentStat = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 4, nullable: true)]
    private ?string $ratio = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $uploadedBytes = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $seedTimeSeconds = null;

    #[ORM\Column]
    private DateTimeImmutable $recordedAt;

    public function __construct()
    {
        $this->recordedAt = new DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getTorrentStat(): ?TorrentStat
    {
        return $this->torrentStat;
    }

    public function setTorrentStat(?TorrentStat $torrentStat): static
    {
        $this->torrentStat = $torrentStat;

        return $this;
    }

    public function getRatio(): ?string
    {
        return $this->ratio;
    }

    public function setRatio(?string $ratio): static
    {
        $this->ratio = $ratio;

        return $this;
    }

    public function getUploadedBytes(): ?int
    {
        return $this->uploadedBytes;
    }

    public function setUploadedBytes(?int $uploadedBytes): static
    {
        $this->uploadedBytes = $uploadedBytes;

        return $this;
    }

    public function getSeedTimeSeconds(): ?int
    {
        return $this->seedTimeSeconds;
    }

    public function setSeedTimeSeconds(?int $seedTimeSeconds): static
    {
        $this->seedTimeSeconds = $seedTimeSeconds;

        return $this;
    }

    public function getRecordedAt(): DateTimeImmutable
    {
        return $this->recordedAt;
    }

    public function setRecordedAt(DateTimeImmutable $recordedAt): static
    {
        $this->recordedAt = $recordedAt;

        return $this;
    }
}
