<?php

namespace App\Tests\Unit\Service;

use App\Entity\MediaFile;
use App\Entity\Movie;
use App\Entity\MovieFile;
use App\Entity\TorrentStat;
use App\Entity\TrackerRule;
use App\Entity\Volume;
use App\Enum\TorrentStatus;
use App\Repository\MediaFileRepository;
use App\Repository\MovieRepository;
use App\Repository\TorrentStatRepository;
use App\Repository\TrackerRuleRepository;
use App\Repository\VolumeRepository;
use App\Service\DeletionService;
use App\Service\SuggestionService;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class SuggestionServiceTest extends TestCase
{
    private MovieRepository&Stub $movieRepository;
    private TorrentStatRepository&Stub $torrentStatRepository;
    private TrackerRuleRepository&Stub $trackerRuleRepository;
    private VolumeRepository&Stub $volumeRepository;
    private SuggestionService $service;

    protected function setUp(): void
    {
        $this->movieRepository = $this->createStub(MovieRepository::class);
        $this->torrentStatRepository = $this->createStub(TorrentStatRepository::class);
        $this->trackerRuleRepository = $this->createStub(TrackerRuleRepository::class);
        $this->volumeRepository = $this->createStub(VolumeRepository::class);

        $this->service = new SuggestionService(
            $this->movieRepository,
            $this->torrentStatRepository,
            $this->trackerRuleRepository,
            $this->volumeRepository,
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(DeletionService::class),
            $this->createStub(MediaFileRepository::class),
        );
    }

    private function createMovieWithFile(
        string $title = 'Test Movie',
        int $fileSize = 1500000000,
        bool $movieProtected = false,
        bool $fileProtected = false,
        int $hardlinkCount = 1,
    ): array {
        $volume = $this->createStub(Volume::class);
        $volume->method('getId')->willReturn(Uuid::v4());
        $volume->method('getName')->willReturn('Test Volume');

        $mediaFile = $this->createStub(MediaFile::class);
        $mediaFile->method('getId')->willReturn(Uuid::v4());
        $mediaFile->method('getFileName')->willReturn('movie.mkv');
        $mediaFile->method('getFilePath')->willReturn('movies/movie.mkv');
        $mediaFile->method('getFileSizeBytes')->willReturn($fileSize);
        $mediaFile->method('getHardlinkCount')->willReturn($hardlinkCount);
        $mediaFile->method('getVolume')->willReturn($volume);
        $mediaFile->method('getResolution')->willReturn('1080p');
        $mediaFile->method('getCodec')->willReturn('x264');
        $mediaFile->method('isProtected')->willReturn($fileProtected);
        $mediaFile->method('getPartialHash')->willReturn(null);

        $movieFile = $this->createStub(MovieFile::class);
        $movieFile->method('getMediaFile')->willReturn($mediaFile);

        $movie = $this->createStub(Movie::class);
        $movie->method('getId')->willReturn(Uuid::v4());
        $movie->method('getTitle')->willReturn($title);
        $movie->method('getYear')->willReturn(2020);
        $movie->method('getPosterUrl')->willReturn(null);
        $movie->method('getGenres')->willReturn('Action');
        $movie->method('getRating')->willReturn('7.5');
        $movie->method('isProtected')->willReturn($movieProtected);
        $movie->method('getMovieFiles')->willReturn(new ArrayCollection([$movieFile]));

        return ['movie' => $movie, 'mediaFile' => $mediaFile, 'volume' => $volume];
    }

    public function testMovieWithoutTorrentsIsOrphan(): void
    {
        $data = $this->createMovieWithFile('Orphan Movie');

        $this->movieRepository->method('findAll')->willReturn([$data['movie']]);
        $this->trackerRuleRepository->method('findAllOrderedByDomain')->willReturn([]);
        $this->torrentStatRepository->method('findByMediaFile')->willReturn([]);

        $result = $this->service->getSuggestions([]);

        $this->assertCount(1, $result['data']);
        $this->assertEquals('orphan', $result['data'][0]['seeding_status']);
        $this->assertEmpty($result['data'][0]['files'][0]['torrents']);
    }

    public function testMovieWithTorrentsReturnsRawData(): void
    {
        $data = $this->createMovieWithFile('Seeding Movie');

        $torrent = $this->createStub(TorrentStat::class);
        $torrent->method('getTorrentHash')->willReturn('abc123');
        $torrent->method('getTorrentName')->willReturn('Movie.2020.1080p');
        $torrent->method('getTrackerDomain')->willReturn('tracker-a.com');
        $torrent->method('getRatio')->willReturn('1.5000');
        $torrent->method('getSeedTimeSeconds')->willReturn(86400);
        $torrent->method('getUploadedBytes')->willReturn(1500000000);
        $torrent->method('getDownloadedBytes')->willReturn(1000000000);
        $torrent->method('getSizeBytes')->willReturn(1000000000);
        $torrent->method('getStatus')->willReturn(TorrentStatus::SEEDING);
        $torrent->method('getAddedAt')->willReturn(new DateTimeImmutable());
        $torrent->method('getLastActivityAt')->willReturn(new DateTimeImmutable());

        $this->movieRepository->method('findAll')->willReturn([$data['movie']]);
        $this->trackerRuleRepository->method('findAllOrderedByDomain')->willReturn([]);
        $this->torrentStatRepository->method('findByMediaFile')->willReturn([$torrent]);

        $result = $this->service->getSuggestions([]);

        $this->assertCount(1, $result['data']);
        $item = $result['data'][0];

        $this->assertEquals('seeding', $item['seeding_status']);
        $this->assertEquals(1.5, $item['best_ratio']);
        $this->assertEquals(1.5, $item['worst_ratio']);
        $this->assertEquals(86400, $item['total_seed_time_max_seconds']);
        $this->assertEquals(1, $item['cross_seed_count']);
        $this->assertFalse($item['blocked_by_tracker_rules']);

        // No score fields
        $this->assertArrayNotHasKey('score', $item);
        $this->assertArrayNotHasKey('score_breakdown', $item);

        // Verify torrent data
        $fileTorrents = $item['files'][0]['torrents'];
        $this->assertCount(1, $fileTorrents);
        $this->assertEquals('abc123', $fileTorrents[0]['torrent_hash']);
    }

    public function testProtectedMovieExcludedByDefault(): void
    {
        $data = $this->createMovieWithFile('Protected Movie', movieProtected: true);

        $this->movieRepository->method('findAll')->willReturn([$data['movie']]);
        $this->trackerRuleRepository->method('findAllOrderedByDomain')->willReturn([]);
        $this->torrentStatRepository->method('findByMediaFile')->willReturn([]);

        $result = $this->service->getSuggestions(['exclude_protected' => true]);

        $this->assertCount(0, $result['data']);
    }

    public function testProtectedMovieIncludedWhenNotExcluded(): void
    {
        $data = $this->createMovieWithFile('Protected Movie', movieProtected: true);

        $this->movieRepository->method('findAll')->willReturn([$data['movie']]);
        $this->trackerRuleRepository->method('findAllOrderedByDomain')->willReturn([]);
        $this->torrentStatRepository->method('findByMediaFile')->willReturn([]);

        $result = $this->service->getSuggestions(['exclude_protected' => false]);

        $this->assertCount(1, $result['data']);
    }

    public function testRealFreedBytesZeroWhenHardlinkCountGreaterThanOne(): void
    {
        $data = $this->createMovieWithFile('Hardlinked Movie', fileSize: 2000000000, hardlinkCount: 3);

        $this->movieRepository->method('findAll')->willReturn([$data['movie']]);
        $this->trackerRuleRepository->method('findAllOrderedByDomain')->willReturn([]);
        $this->torrentStatRepository->method('findByMediaFile')->willReturn([]);

        $result = $this->service->getSuggestions([]);

        $this->assertCount(1, $result['data']);
        $this->assertEquals(0, $result['data'][0]['files'][0]['real_freed_bytes']);
    }

    public function testRealFreedBytesEqualsFileSizeWhenSingleLink(): void
    {
        $data = $this->createMovieWithFile('Single Link Movie', fileSize: 2000000000, hardlinkCount: 1);

        $this->movieRepository->method('findAll')->willReturn([$data['movie']]);
        $this->trackerRuleRepository->method('findAllOrderedByDomain')->willReturn([]);
        $this->torrentStatRepository->method('findByMediaFile')->willReturn([]);

        $result = $this->service->getSuggestions([]);

        $this->assertCount(1, $result['data']);
        $this->assertEquals(2000000000, $result['data'][0]['files'][0]['real_freed_bytes']);
    }

    public function testBlockedByTrackerRulesDetected(): void
    {
        $data = $this->createMovieWithFile('Blocked Movie');

        $torrent = $this->createStub(TorrentStat::class);
        $torrent->method('getTorrentHash')->willReturn('blockhash');
        $torrent->method('getTorrentName')->willReturn('Movie.2020');
        $torrent->method('getTrackerDomain')->willReturn('strict-tracker.org');
        $torrent->method('getRatio')->willReturn('0.5000');
        $torrent->method('getSeedTimeSeconds')->willReturn(3600); // 1 hour
        $torrent->method('getUploadedBytes')->willReturn(500000000);
        $torrent->method('getDownloadedBytes')->willReturn(1000000000);
        $torrent->method('getSizeBytes')->willReturn(1000000000);
        $torrent->method('getStatus')->willReturn(TorrentStatus::SEEDING);
        $torrent->method('getAddedAt')->willReturn(new DateTimeImmutable());
        $torrent->method('getLastActivityAt')->willReturn(new DateTimeImmutable());

        $trackerRule = $this->createStub(TrackerRule::class);
        $trackerRule->method('getTrackerDomain')->willReturn('strict-tracker.org');
        $trackerRule->method('getMinRatio')->willReturn('1.0000');
        $trackerRule->method('getMinSeedTimeHours')->willReturn(720);

        $this->movieRepository->method('findAll')->willReturn([$data['movie']]);
        $this->trackerRuleRepository->method('findAllOrderedByDomain')->willReturn([$trackerRule]);
        $this->torrentStatRepository->method('findByMediaFile')->willReturn([$torrent]);

        $result = $this->service->getSuggestions([]);

        $this->assertCount(1, $result['data']);
        $this->assertTrue($result['data'][0]['blocked_by_tracker_rules']);
        $this->assertTrue($result['data'][0]['files'][0]['blocked_by_tracker_rules']);
        $this->assertNotNull($result['data'][0]['files'][0]['tracker_block_reason']);
    }

    public function testPagination(): void
    {
        $movies = [];
        for ($i = 0; $i < 5; ++$i) {
            $data = $this->createMovieWithFile("Movie $i");
            $movies[] = $data['movie'];
        }

        $this->movieRepository->method('findAll')->willReturn($movies);
        $this->trackerRuleRepository->method('findAllOrderedByDomain')->willReturn([]);
        $this->torrentStatRepository->method('findByMediaFile')->willReturn([]);

        $result = $this->service->getSuggestions(['page' => 1, 'per_page' => 2]);

        $this->assertCount(2, $result['data']);
        $this->assertEquals(5, $result['meta']['total']);
        $this->assertEquals(3, $result['meta']['total_pages']);
        $this->assertEquals(1, $result['meta']['page']);
    }
}
