<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MovieFileRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: MovieFileRepository::class)]
#[ORM\Table(name: 'movie_files')]
#[ORM\UniqueConstraint(name: 'unique_movie_media_file', columns: ['movie_id', 'media_file_id'])]
class MovieFile
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Movie::class, inversedBy: 'movieFiles')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Movie $movie = null;

    #[ORM\ManyToOne(targetEntity: MediaFile::class, inversedBy: 'movieFiles')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?MediaFile $mediaFile = null;

    #[ORM\Column(length: 30)]
    private string $matchedBy = 'filename';

    #[ORM\Column(type: 'decimal', precision: 3, scale: 2, nullable: true)]
    private ?string $confidence = '1.00';

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

    public function getMovie(): ?Movie
    {
        return $this->movie;
    }

    public function setMovie(?Movie $movie): static
    {
        $this->movie = $movie;

        return $this;
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

    public function getMatchedBy(): string
    {
        return $this->matchedBy;
    }

    public function setMatchedBy(string $matchedBy): static
    {
        $this->matchedBy = $matchedBy;

        return $this;
    }

    public function getConfidence(): ?string
    {
        return $this->confidence;
    }

    public function setConfidence(?string $confidence): static
    {
        $this->confidence = $confidence;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
