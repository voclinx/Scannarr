<?php

namespace App\Entity;

use App\Repository\MovieRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: MovieRepository::class)]
#[ORM\Table(name: 'movies')]
#[ORM\Index(name: 'idx_movies_tmdb', columns: ['tmdb_id'])]
#[ORM\Index(name: 'idx_movies_title', columns: ['title'])]
#[ORM\HasLifecycleCallbacks]
class Movie
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(unique: true, nullable: true)]
    private ?int $tmdbId = null;

    #[ORM\Column(nullable: true)]
    private ?int $radarrId = null;

    #[ORM\Column(length: 500)]
    private ?string $title = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $originalTitle = null;

    #[ORM\Column(nullable: true)]
    private ?int $year = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $synopsis = null;

    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $posterUrl = null;

    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $backdropUrl = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $genres = null;

    #[ORM\Column(type: 'decimal', precision: 3, scale: 1, nullable: true)]
    private ?string $rating = null;

    #[ORM\Column(nullable: true)]
    private ?int $runtimeMinutes = null;

    #[ORM\ManyToOne(targetEntity: RadarrInstance::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?RadarrInstance $radarrInstance = null;

    #[ORM\Column(nullable: true)]
    private ?bool $radarrMonitored = true;

    #[ORM\Column(nullable: true)]
    private ?bool $radarrHasFile = false;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    /** @var Collection<int, MovieFile> */
    #[ORM\OneToMany(targetEntity: MovieFile::class, mappedBy: 'movie', cascade: ['remove'])]
    private Collection $movieFiles;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
        $this->movieFiles = new ArrayCollection();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getTmdbId(): ?int
    {
        return $this->tmdbId;
    }

    public function setTmdbId(?int $tmdbId): static
    {
        $this->tmdbId = $tmdbId;

        return $this;
    }

    public function getRadarrId(): ?int
    {
        return $this->radarrId;
    }

    public function setRadarrId(?int $radarrId): static
    {
        $this->radarrId = $radarrId;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getOriginalTitle(): ?string
    {
        return $this->originalTitle;
    }

    public function setOriginalTitle(?string $originalTitle): static
    {
        $this->originalTitle = $originalTitle;

        return $this;
    }

    public function getYear(): ?int
    {
        return $this->year;
    }

    public function setYear(?int $year): static
    {
        $this->year = $year;

        return $this;
    }

    public function getSynopsis(): ?string
    {
        return $this->synopsis;
    }

    public function setSynopsis(?string $synopsis): static
    {
        $this->synopsis = $synopsis;

        return $this;
    }

    public function getPosterUrl(): ?string
    {
        return $this->posterUrl;
    }

    public function setPosterUrl(?string $posterUrl): static
    {
        $this->posterUrl = $posterUrl;

        return $this;
    }

    public function getBackdropUrl(): ?string
    {
        return $this->backdropUrl;
    }

    public function setBackdropUrl(?string $backdropUrl): static
    {
        $this->backdropUrl = $backdropUrl;

        return $this;
    }

    public function getGenres(): ?string
    {
        return $this->genres;
    }

    public function setGenres(?string $genres): static
    {
        $this->genres = $genres;

        return $this;
    }

    public function getRating(): ?string
    {
        return $this->rating;
    }

    public function setRating(?string $rating): static
    {
        $this->rating = $rating;

        return $this;
    }

    public function getRuntimeMinutes(): ?int
    {
        return $this->runtimeMinutes;
    }

    public function setRuntimeMinutes(?int $runtimeMinutes): static
    {
        $this->runtimeMinutes = $runtimeMinutes;

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

    public function isRadarrMonitored(): ?bool
    {
        return $this->radarrMonitored;
    }

    public function setRadarrMonitored(?bool $radarrMonitored): static
    {
        $this->radarrMonitored = $radarrMonitored;

        return $this;
    }

    public function isRadarrHasFile(): ?bool
    {
        return $this->radarrHasFile;
    }

    public function setRadarrHasFile(?bool $radarrHasFile): static
    {
        $this->radarrHasFile = $radarrHasFile;

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

    /** @return Collection<int, MovieFile> */
    public function getMovieFiles(): Collection
    {
        return $this->movieFiles;
    }
}
