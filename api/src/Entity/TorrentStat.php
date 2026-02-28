<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TorrentStatus;
use App\Repository\TorrentStatRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TorrentStatRepository::class)]
#[ORM\Table(name: 'torrent_stats')]
#[ORM\Index(name: 'idx_torrent_stats_media_file', columns: ['media_file_id'])]
#[ORM\Index(name: 'idx_torrent_stats_tracker', columns: ['tracker_domain'])]
#[ORM\Index(name: 'idx_torrent_stats_hash', columns: ['torrent_hash'])]
#[ORM\UniqueConstraint(name: 'uniq_torrent_hash', columns: ['torrent_hash'])]
#[ORM\HasLifecycleCallbacks]
class TorrentStat
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: MediaFile::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?MediaFile $mediaFile = null;

    #[ORM\Column(length: 100)]
    private ?string $torrentHash = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $torrentName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $trackerDomain = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 4)]
    private string $ratio = '0.0000';

    #[ORM\Column(type: 'bigint')]
    private int $seedTimeSeconds = 0;

    #[ORM\Column(type: 'bigint')]
    private int $uploadedBytes = 0;

    #[ORM\Column(type: 'bigint')]
    private int $downloadedBytes = 0;

    #[ORM\Column(type: 'bigint')]
    private int $sizeBytes = 0;

    #[ORM\Column(length: 30, enumType: TorrentStatus::class)]
    private TorrentStatus $status = TorrentStatus::SEEDING;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $addedAt = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $lastActivityAt = null;

    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $qbitContentPath = null;

    #[ORM\Column]
    private DateTimeImmutable $firstSeenAt;

    #[ORM\Column]
    private DateTimeImmutable $lastSyncedAt;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    /** @var Collection<int, TorrentStatHistory> */
    #[ORM\OneToMany(targetEntity: TorrentStatHistory::class, mappedBy: 'torrentStat', cascade: ['remove'])]
    private Collection $history;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
        $this->firstSeenAt = new DateTimeImmutable();
        $this->lastSyncedAt = new DateTimeImmutable();
        $this->history = new ArrayCollection();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getMediaFile(): ?MediaFile
    {
        return $this->mediaFile;
    }

    public function setMediaFile(?MediaFile $mediaFile): static
    {
        $this->mediaFile = $mediaFile;

        return $this;
    }

    public function getTorrentHash(): ?string
    {
        return $this->torrentHash;
    }

    public function setTorrentHash(string $torrentHash): static
    {
        $this->torrentHash = $torrentHash;

        return $this;
    }

    public function getTorrentName(): ?string
    {
        return $this->torrentName;
    }

    public function setTorrentName(?string $torrentName): static
    {
        $this->torrentName = $torrentName;

        return $this;
    }

    public function getTrackerDomain(): ?string
    {
        return $this->trackerDomain;
    }

    public function setTrackerDomain(?string $trackerDomain): static
    {
        $this->trackerDomain = $trackerDomain;

        return $this;
    }

    public function getRatio(): string
    {
        return $this->ratio;
    }

    public function setRatio(string $ratio): static
    {
        $this->ratio = $ratio;

        return $this;
    }

    public function getSeedTimeSeconds(): int
    {
        return $this->seedTimeSeconds;
    }

    public function setSeedTimeSeconds(int $seedTimeSeconds): static
    {
        $this->seedTimeSeconds = $seedTimeSeconds;

        return $this;
    }

    public function getUploadedBytes(): int
    {
        return $this->uploadedBytes;
    }

    public function setUploadedBytes(int $uploadedBytes): static
    {
        $this->uploadedBytes = $uploadedBytes;

        return $this;
    }

    public function getDownloadedBytes(): int
    {
        return $this->downloadedBytes;
    }

    public function setDownloadedBytes(int $downloadedBytes): static
    {
        $this->downloadedBytes = $downloadedBytes;

        return $this;
    }

    public function getSizeBytes(): int
    {
        return $this->sizeBytes;
    }

    public function setSizeBytes(int $sizeBytes): static
    {
        $this->sizeBytes = $sizeBytes;

        return $this;
    }

    public function getStatus(): TorrentStatus
    {
        return $this->status;
    }

    public function setStatus(TorrentStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getAddedAt(): ?DateTimeImmutable
    {
        return $this->addedAt;
    }

    public function setAddedAt(?DateTimeImmutable $addedAt): static
    {
        $this->addedAt = $addedAt;

        return $this;
    }

    public function getLastActivityAt(): ?DateTimeImmutable
    {
        return $this->lastActivityAt;
    }

    public function setLastActivityAt(?DateTimeImmutable $lastActivityAt): static
    {
        $this->lastActivityAt = $lastActivityAt;

        return $this;
    }

    public function getQbitContentPath(): ?string
    {
        return $this->qbitContentPath;
    }

    public function setQbitContentPath(?string $qbitContentPath): static
    {
        $this->qbitContentPath = $qbitContentPath;

        return $this;
    }

    public function getFirstSeenAt(): DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function setFirstSeenAt(DateTimeImmutable $firstSeenAt): static
    {
        $this->firstSeenAt = $firstSeenAt;

        return $this;
    }

    public function getLastSyncedAt(): DateTimeImmutable
    {
        return $this->lastSyncedAt;
    }

    public function setLastSyncedAt(DateTimeImmutable $lastSyncedAt): static
    {
        $this->lastSyncedAt = $lastSyncedAt;

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

    /**
     * @return Collection<int, TorrentStatHistory>
     */
    public function getHistory(): Collection
    {
        return $this->history;
    }
}
