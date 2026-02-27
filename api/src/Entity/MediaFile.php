<?php

namespace App\Entity;

use App\Repository\MediaFileRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: MediaFileRepository::class)]
#[ORM\Table(name: 'media_files')]
#[ORM\UniqueConstraint(name: 'unique_volume_file_path', columns: ['volume_id', 'file_path'])]
#[ORM\Index(name: 'idx_media_files_volume', columns: ['volume_id'])]
#[ORM\Index(name: 'idx_media_files_radarr', columns: ['is_linked_radarr'])]
#[ORM\Index(name: 'idx_media_files_name', columns: ['file_name'])]
#[ORM\Index(name: 'idx_media_files_partial_hash', columns: ['partial_hash'])]
#[ORM\HasLifecycleCallbacks]
class MediaFile
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Volume::class, inversedBy: 'mediaFiles')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Volume $volume = null;

    #[ORM\Column(length: 1000)]
    private ?string $filePath = null;

    #[ORM\Column(length: 500)]
    private ?string $fileName = null;

    #[ORM\Column(type: 'bigint')]
    private int $fileSizeBytes = 0;

    #[ORM\Column]
    private int $hardlinkCount = 1;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $resolution = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $codec = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $quality = null;

    #[ORM\Column]
    private bool $isLinkedRadarr = false;

    #[ORM\Column]
    private bool $isLinkedMediaPlayer = false;

    #[ORM\ManyToOne(targetEntity: RadarrInstance::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?RadarrInstance $radarrInstance = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $fileHash = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $partialHash = null;

    #[ORM\Column]
    private bool $isProtected = false;

    #[ORM\Column]
    private DateTimeImmutable $detectedAt;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    /** @var Collection<int, MovieFile> */
    #[ORM\OneToMany(targetEntity: MovieFile::class, mappedBy: 'mediaFile', cascade: ['remove'])]
    private Collection $movieFiles;

    public function __construct()
    {
        $this->detectedAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
        $this->movieFiles = new ArrayCollection();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getVolume(): ?Volume
    {
        return $this->volume;
    }

    public function setVolume(?Volume $volume): static
    {
        $this->volume = $volume;

        return $this;
    }

    public function getFilePath(): ?string
    {
        return $this->filePath;
    }

    public function setFilePath(string $filePath): static
    {
        $this->filePath = $filePath;

        return $this;
    }

    public function getFileName(): ?string
    {
        return $this->fileName;
    }

    public function setFileName(string $fileName): static
    {
        $this->fileName = $fileName;

        return $this;
    }

    public function getFileSizeBytes(): int
    {
        return $this->fileSizeBytes;
    }

    public function setFileSizeBytes(int $fileSizeBytes): static
    {
        $this->fileSizeBytes = $fileSizeBytes;

        return $this;
    }

    public function getHardlinkCount(): int
    {
        return $this->hardlinkCount;
    }

    public function setHardlinkCount(int $hardlinkCount): static
    {
        $this->hardlinkCount = $hardlinkCount;

        return $this;
    }

    public function getResolution(): ?string
    {
        return $this->resolution;
    }

    public function setResolution(?string $resolution): static
    {
        $this->resolution = $resolution;

        return $this;
    }

    public function getCodec(): ?string
    {
        return $this->codec;
    }

    public function setCodec(?string $codec): static
    {
        $this->codec = $codec;

        return $this;
    }

    public function getQuality(): ?string
    {
        return $this->quality;
    }

    public function setQuality(?string $quality): static
    {
        $this->quality = $quality;

        return $this;
    }

    public function isLinkedRadarr(): bool
    {
        return $this->isLinkedRadarr;
    }

    public function setIsLinkedRadarr(bool $isLinkedRadarr): static
    {
        $this->isLinkedRadarr = $isLinkedRadarr;

        return $this;
    }

    public function isLinkedMediaPlayer(): bool
    {
        return $this->isLinkedMediaPlayer;
    }

    public function setIsLinkedMediaPlayer(bool $isLinkedMediaPlayer): static
    {
        $this->isLinkedMediaPlayer = $isLinkedMediaPlayer;

        return $this;
    }

    public function getRadarrInstance(): ?RadarrInstance
    {
        return $this->radarrInstance;
    }

    public function setRadarrInstance(?RadarrInstance $radarrInstance): static
    {
        $this->radarrInstance = $radarrInstance;

        return $this;
    }

    public function getFileHash(): ?string
    {
        return $this->fileHash;
    }

    public function setFileHash(?string $fileHash): static
    {
        $this->fileHash = $fileHash;

        return $this;
    }

    public function getPartialHash(): ?string
    {
        return $this->partialHash;
    }

    public function setPartialHash(?string $partialHash): static
    {
        $this->partialHash = $partialHash;

        return $this;
    }

    public function isProtected(): bool
    {
        return $this->isProtected;
    }

    public function setIsProtected(bool $isProtected): static
    {
        $this->isProtected = $isProtected;

        return $this;
    }

    public function getDetectedAt(): DateTimeImmutable
    {
        return $this->detectedAt;
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

    /** @return Collection<int, MovieFile> */
    public function getMovieFiles(): Collection
    {
        return $this->movieFiles;
    }
}
