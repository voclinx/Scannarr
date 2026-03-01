<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\MediaFile;
use App\Entity\Movie;
use App\Entity\MovieFile;
use App\Entity\RadarrInstance;
use App\Entity\Setting;
use App\Entity\TorrentStat;
use App\Entity\TorrentStatHistory;
use App\Entity\TrackerRule;
use App\Enum\TorrentStatus;
use App\ExternalService\MediaManager\RadarrService;
use App\ExternalService\TorrentClient\QBittorrentService;
use App\Repository\MediaFileRepository;
use App\Repository\MovieRepository;
use App\Repository\RadarrInstanceRepository;
use App\Repository\SettingRepository;
use App\Repository\TorrentStatHistoryRepository;
use App\Repository\TorrentStatRepository;
use App\Repository\TrackerRuleRepository;
use App\Service\FileMatchingService;
use App\Service\QBittorrentSyncService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

#[AllowMockObjectsWithoutExpectations]
class QBittorrentSyncServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private QBittorrentService&MockObject $qbitService;
    private RadarrService&MockObject $radarrService;
    private MediaFileRepository&MockObject $mediaFileRepository;
    private MovieRepository&MockObject $movieRepository;
    private TorrentStatRepository&MockObject $torrentStatRepository;
    private TorrentStatHistoryRepository&MockObject $historyRepository;
    private TrackerRuleRepository&MockObject $trackerRuleRepository;
    private SettingRepository&MockObject $settingRepository;
    private FileMatchingService&MockObject $fileMatchingService;
    private RadarrInstanceRepository&MockObject $radarrInstanceRepository;
    private LoggerInterface&MockObject $logger;
    private QBittorrentSyncService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->qbitService = $this->createMock(QBittorrentService::class);
        $this->radarrService = $this->createMock(RadarrService::class);
        $this->fileMatchingService = $this->createMock(FileMatchingService::class);
        $this->mediaFileRepository = $this->createMock(MediaFileRepository::class);
        $this->movieRepository = $this->createMock(MovieRepository::class);
        $this->torrentStatRepository = $this->createMock(TorrentStatRepository::class);
        $this->historyRepository = $this->createMock(TorrentStatHistoryRepository::class);
        $this->trackerRuleRepository = $this->createMock(TrackerRuleRepository::class);
        $this->settingRepository = $this->createMock(SettingRepository::class);
        $this->radarrInstanceRepository = $this->createMock(RadarrInstanceRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new QBittorrentSyncService(
            $this->em,
            $this->qbitService,
            $this->radarrService,
            $this->fileMatchingService,
            $this->mediaFileRepository,
            $this->movieRepository,
            $this->torrentStatRepository,
            $this->historyRepository,
            $this->trackerRuleRepository,
            $this->settingRepository,
            $this->radarrInstanceRepository,
            $this->logger,
        );
    }

    // -------------------------------------------------------------------
    // extractTrackerDomain (via reflection)
    // -------------------------------------------------------------------

    public function testExtractTrackerDomainFromHttpUrl(): void
    {
        $method = new ReflectionMethod($this->service, 'extractTrackerDomain');

        $this->assertSame('tracker.example.com', $method->invoke($this->service, 'https://tracker.example.com/announce'));
    }

    public function testExtractTrackerDomainFromUdpUrl(): void
    {
        $method = new ReflectionMethod($this->service, 'extractTrackerDomain');

        $this->assertSame('tracker.opentracker.org', $method->invoke($this->service, 'udp://tracker.opentracker.org:1337/announce'));
    }

    public function testExtractTrackerDomainFromUrlWithPort(): void
    {
        $method = new ReflectionMethod($this->service, 'extractTrackerDomain');

        $this->assertSame('tracker.example.com', $method->invoke($this->service, 'http://tracker.example.com:8080/announce'));
    }

    public function testExtractTrackerDomainFromEmptyString(): void
    {
        $method = new ReflectionMethod($this->service, 'extractTrackerDomain');

        $this->assertSame('', $method->invoke($this->service, ''));
    }

    public function testExtractTrackerDomainFromInvalidUrl(): void
    {
        $method = new ReflectionMethod($this->service, 'extractTrackerDomain');

        $this->assertSame('', $method->invoke($this->service, 'not-a-url'));
    }

    // -------------------------------------------------------------------
    // mapQbitStatus (via reflection)
    // -------------------------------------------------------------------

    public function testMapQbitStatusSeeding(): void
    {
        $method = new ReflectionMethod($this->service, 'mapQbitStatus');

        $this->assertSame(TorrentStatus::SEEDING, $method->invoke($this->service, 'uploading'));
        $this->assertSame(TorrentStatus::SEEDING, $method->invoke($this->service, 'stalledUP'));
        $this->assertSame(TorrentStatus::SEEDING, $method->invoke($this->service, 'forcedUP'));
        $this->assertSame(TorrentStatus::SEEDING, $method->invoke($this->service, 'queuedUP'));
        $this->assertSame(TorrentStatus::SEEDING, $method->invoke($this->service, 'checkingUP'));
    }

    public function testMapQbitStatusPaused(): void
    {
        $method = new ReflectionMethod($this->service, 'mapQbitStatus');

        $this->assertSame(TorrentStatus::PAUSED, $method->invoke($this->service, 'pausedUP'));
        $this->assertSame(TorrentStatus::PAUSED, $method->invoke($this->service, 'pausedDL'));
    }

    public function testMapQbitStatusStalled(): void
    {
        $method = new ReflectionMethod($this->service, 'mapQbitStatus');

        $this->assertSame(TorrentStatus::STALLED, $method->invoke($this->service, 'stalledDL'));
        $this->assertSame(TorrentStatus::STALLED, $method->invoke($this->service, 'downloading'));
        $this->assertSame(TorrentStatus::STALLED, $method->invoke($this->service, 'metaDL'));
    }

    public function testMapQbitStatusError(): void
    {
        $method = new ReflectionMethod($this->service, 'mapQbitStatus');

        $this->assertSame(TorrentStatus::ERROR, $method->invoke($this->service, 'error'));
        $this->assertSame(TorrentStatus::ERROR, $method->invoke($this->service, 'missingFiles'));
    }

    public function testMapQbitStatusUnknownDefaultsToSeeding(): void
    {
        $method = new ReflectionMethod($this->service, 'mapQbitStatus');

        $this->assertSame(TorrentStatus::SEEDING, $method->invoke($this->service, 'unknownState'));
    }

    // -------------------------------------------------------------------
    // sync() — not configured
    // -------------------------------------------------------------------

    public function testSyncBailsWhenNotConfigured(): void
    {
        $this->qbitService->method('isConfigured')->willReturn(false);

        $result = $this->service->sync();

        $this->assertSame(0, $result['torrents_synced']);
        $this->assertSame(0, $result['errors']);
    }

    // -------------------------------------------------------------------
    // sync() — qBit error
    // -------------------------------------------------------------------

    public function testSyncHandlesQbitConnectionError(): void
    {
        $this->qbitService->method('isConfigured')->willReturn(true);
        $this->qbitService->method('getAllTorrents')
            ->willThrowException(new RuntimeException('Connection refused'));

        $this->settingRepository->method('setValue')->willReturn(new Setting());

        $result = $this->service->sync();

        $this->assertSame(0, $result['torrents_synced']);
        $this->assertSame(1, $result['errors']);
    }

    // -------------------------------------------------------------------
    // sync() — matching by Radarr hash
    // -------------------------------------------------------------------

    public function testSyncMatchesByRadarrHash(): void
    {
        $this->qbitService->method('isConfigured')->willReturn(true);

        $torrents = [
            [
                'hash' => 'abc123def456',
                'name' => 'Test Movie',
                'tracker' => 'https://tracker.example.com/announce',
                'content_path' => '/downloads/movie.mkv',
                'ratio' => 1.5,
                'seeding_time' => 3600,
                'uploaded' => 1500000000,
                'downloaded' => 1000000000,
                'size' => 1000000000,
                'state' => 'uploading',
                'added_on' => 1700000000,
                'last_activity' => 1700001000,
            ],
        ];

        $this->qbitService->method('getAllTorrents')->willReturn($torrents);

        // Radarr history
        $instance = new RadarrInstance();
        $instance->setName('Radarr Main');
        $instance->setUrl('http://localhost:7878');
        $instance->setApiKey('test-key');

        $this->radarrInstanceRepository->method('findBy')->willReturn([$instance]);
        $this->radarrService->method('getHistory')->willReturn([
            ['downloadId' => 'ABC123DEF456', 'movieId' => 42],
        ]);

        // Movie with file
        $mediaFile = new MediaFile();
        $movieFile = new MovieFile();
        $movieFile->setMediaFile($mediaFile);

        $movie = new Movie();
        $movie->setTitle('Test Movie');

        // Use reflection to add movieFile to movie's collection
        $ref = new ReflectionClass($movie);
        $prop = $ref->getProperty('movieFiles');
        $collection = $prop->getValue($movie);
        $collection->add($movieFile);

        $this->movieRepository->method('findByRadarrIdAndInstance')->willReturn($movie);

        // Tracker auto-detect
        $this->trackerRuleRepository->method('findByDomain')->willReturn(null);

        // Torrent stat not found (new)
        $this->torrentStatRepository->method('findByHash')->willReturn(null);
        $this->torrentStatRepository->method('findNotSeenSince')->willReturn([]);

        // No snapshot today
        $this->historyRepository->method('hasSnapshotToday')->willReturn(false);

        $this->em->expects($this->atLeastOnce())->method('persist');
        $this->em->expects($this->atLeastOnce())->method('flush');
        $this->settingRepository->method('setValue')->willReturn(new Setting());

        $result = $this->service->sync();

        $this->assertSame(1, $result['torrents_synced']);
        $this->assertSame(0, $result['unmatched']);
        $this->assertSame(1, $result['new_trackers']);
    }

    // -------------------------------------------------------------------
    // sync() — matching by content path
    // -------------------------------------------------------------------

    public function testSyncMatchesByContentPath(): void
    {
        $this->qbitService->method('isConfigured')->willReturn(true);

        $torrents = [
            [
                'hash' => 'notradarrhash',
                'name' => 'Path Match Movie',
                'tracker' => '',
                'content_path' => '/downloads/movies/Movie (2024)/movie.mkv',
                'ratio' => 0.5,
                'seeding_time' => 1800,
                'uploaded' => 500000000,
                'downloaded' => 1000000000,
                'size' => 1000000000,
                'state' => 'stalledUP',
                'added_on' => 1700000000,
                'last_activity' => 1700001000,
            ],
        ];

        $this->qbitService->method('getAllTorrents')->willReturn($torrents);

        $this->radarrInstanceRepository->method('findBy')->willReturn([]);

        // FileMatchingService returns a match for the content_path
        $mediaFile = new MediaFile();
        $matchResult = new \App\Contract\Matching\MatchResult(
            mediaFile: $mediaFile,
            strategy: 'path_suffix',
            confidence: 1.0,
        );
        $this->fileMatchingService->method('match')
            ->with('/downloads/movies/Movie (2024)/movie.mkv')
            ->willReturn($matchResult);

        $this->torrentStatRepository->method('findByHash')->willReturn(null);
        $this->torrentStatRepository->method('findNotSeenSince')->willReturn([]);
        $this->historyRepository->method('hasSnapshotToday')->willReturn(false);

        $this->em->expects($this->atLeastOnce())->method('persist');
        $this->em->expects($this->atLeastOnce())->method('flush');
        $this->settingRepository->method('setValue')->willReturn(new Setting());

        $result = $this->service->sync();

        $this->assertSame(1, $result['torrents_synced']);
        $this->assertSame(0, $result['unmatched']);
    }

    // -------------------------------------------------------------------
    // sync() — unmatched torrent
    // -------------------------------------------------------------------

    public function testSyncCountsUnmatchedTorrents(): void
    {
        $this->qbitService->method('isConfigured')->willReturn(true);

        $torrents = [
            [
                'hash' => 'unmatchedhash',
                'name' => 'Unknown Torrent',
                'tracker' => '',
                'content_path' => '/downloads/unknown/file.txt',
                'ratio' => 0,
                'seeding_time' => 0,
                'uploaded' => 0,
                'downloaded' => 0,
                'size' => 100,
                'state' => 'downloading',
                'added_on' => 0,
                'last_activity' => 0,
            ],
        ];

        $this->qbitService->method('getAllTorrents')->willReturn($torrents);
        $this->qbitService->method('getTorrentFiles')
            ->willReturn([['name' => 'file.txt', 'size' => 100]]);

        $this->radarrInstanceRepository->method('findBy')->willReturn([]);
        $this->fileMatchingService->method('match')->willReturn(null);
        $this->torrentStatRepository->method('findNotSeenSince')->willReturn([]);

        $this->em->expects($this->atLeastOnce())->method('flush');
        $this->settingRepository->method('setValue')->willReturn(new Setting());

        $result = $this->service->sync();

        $this->assertSame(0, $result['torrents_synced']);
        $this->assertSame(1, $result['unmatched']);
    }

    // -------------------------------------------------------------------
    // sync() — snapshot creation (no duplicate)
    // -------------------------------------------------------------------

    public function testSyncDoesNotCreateDuplicateSnapshot(): void
    {
        $this->qbitService->method('isConfigured')->willReturn(true);

        $torrents = [
            [
                'hash' => 'snaphash',
                'name' => 'Snapshot Movie',
                'tracker' => 'https://tracker.test.com/announce',
                'content_path' => '/downloads/snap.mkv',
                'ratio' => 2.0,
                'seeding_time' => 7200,
                'uploaded' => 2000000000,
                'downloaded' => 1000000000,
                'size' => 1000000000,
                'state' => 'uploading',
                'added_on' => 1700000000,
                'last_activity' => 1700001000,
            ],
        ];

        $this->qbitService->method('getAllTorrents')->willReturn($torrents);

        $this->radarrInstanceRepository->method('findBy')->willReturn([]);

        // FileMatchingService returns a match for the content_path
        $mediaFile = new MediaFile();
        $matchResult = new \App\Contract\Matching\MatchResult(
            mediaFile: $mediaFile,
            strategy: 'path_suffix',
            confidence: 1.0,
        );
        $this->fileMatchingService->method('match')->willReturn($matchResult);

        $existingRule = new TrackerRule();
        $existingRule->setTrackerDomain('tracker.test.com');
        $this->trackerRuleRepository->method('findByDomain')->willReturn($existingRule);

        $this->torrentStatRepository->method('findByHash')->willReturn(null);
        $this->torrentStatRepository->method('findNotSeenSince')->willReturn([]);

        // Snapshot already exists today
        $this->historyRepository->method('hasSnapshotToday')->willReturn(true);

        $persistedClasses = [];
        $this->em->method('persist')
            ->willReturnCallback(function (object $entity) use (&$persistedClasses): void {
                $persistedClasses[] = $entity::class;
            });
        $this->em->expects($this->atLeastOnce())->method('flush');
        $this->settingRepository->method('setValue')->willReturn(new Setting());

        $result = $this->service->sync();

        $this->assertSame(1, $result['torrents_synced']);
        // TorrentStatHistory should NOT be persisted (snapshot already exists)
        $this->assertNotContains(TorrentStatHistory::class, $persistedClasses);
    }

    // -------------------------------------------------------------------
    // sync() — stale torrents marked as REMOVED
    // -------------------------------------------------------------------

    public function testSyncMarksStaleTorrentsAsRemoved(): void
    {
        $this->qbitService->method('isConfigured')->willReturn(true);
        $this->qbitService->method('getAllTorrents')->willReturn([]);

        $this->radarrInstanceRepository->method('findBy')->willReturn([]);

        // Stale torrent
        $staleStat = new TorrentStat();
        $staleStat->setTorrentHash('stalehash');
        $staleStat->setStatus(TorrentStatus::SEEDING);

        $this->torrentStatRepository->method('findNotSeenSince')->willReturn([$staleStat]);

        $this->em->expects($this->atLeastOnce())->method('flush');
        $this->settingRepository->method('setValue')->willReturn(new Setting());

        $result = $this->service->sync();

        $this->assertSame(1, $result['stale_removed']);
        $this->assertSame(TorrentStatus::REMOVED, $staleStat->getStatus());
    }

    public function testSyncDoesNotReRemoveAlreadyRemovedTorrents(): void
    {
        $this->qbitService->method('isConfigured')->willReturn(true);
        $this->qbitService->method('getAllTorrents')->willReturn([]);

        $this->radarrInstanceRepository->method('findBy')->willReturn([]);

        // Already removed torrent
        $staleStat = new TorrentStat();
        $staleStat->setTorrentHash('removedhash');
        $staleStat->setStatus(TorrentStatus::REMOVED);

        $this->torrentStatRepository->method('findNotSeenSince')->willReturn([$staleStat]);

        $this->em->expects($this->atLeastOnce())->method('flush');
        $this->settingRepository->method('setValue')->willReturn(new Setting());

        $result = $this->service->sync();

        $this->assertSame(0, $result['stale_removed']);
    }

    // -------------------------------------------------------------------
    // matchByContentPath — no volume match
    // -------------------------------------------------------------------

    public function testMatchByContentPathNoMatch(): void
    {
        $method = new ReflectionMethod($this->service, 'matchByContentPath');

        // FileMatchingService returns null (no match)
        $this->fileMatchingService->method('match')->willReturn(null);

        $result = $method->invoke($this->service, '/downloads/file.mkv');

        $this->assertNull($result);
    }

    public function testMatchByContentPathEmptyPath(): void
    {
        $method = new ReflectionMethod($this->service, 'matchByContentPath');

        $result = $method->invoke($this->service, '');

        $this->assertNull($result);
    }

    // -------------------------------------------------------------------
    // matchByFileSize — cross-seed fallback
    // -------------------------------------------------------------------

    public function testMatchByFileSizeSingleMatch(): void
    {
        $method = new ReflectionMethod($this->service, 'matchByFileSize');

        $this->qbitService->method('getTorrentFiles')->willReturn([
            ['name' => 'movie.mkv', 'size' => 5000000000],
            ['name' => 'movie.nfo', 'size' => 1024],
        ]);

        $mediaFile = new MediaFile();
        $this->mediaFileRepository->method('findByFileSizeBytes')
            ->with(5000000000)
            ->willReturn([$mediaFile]);

        $result = $method->invoke($this->service, ['hash' => 'testhash']);

        $this->assertSame($mediaFile, $result);
    }

    public function testMatchByFileSizeNoMediaFiles(): void
    {
        $method = new ReflectionMethod($this->service, 'matchByFileSize');

        $this->qbitService->method('getTorrentFiles')->willReturn([
            ['name' => 'document.pdf', 'size' => 1024],
        ]);

        $result = $method->invoke($this->service, ['hash' => 'testhash']);

        $this->assertNull($result);
    }

    public function testMatchByFileSizeMultipleMatchesNoHashMatch(): void
    {
        $method = new ReflectionMethod($this->service, 'matchByFileSize');

        $this->qbitService->method('getTorrentFiles')->willReturn([
            ['name' => 'movie.mkv', 'size' => 5000000000],
        ]);

        $mediaFile1 = new MediaFile();
        $mediaFile2 = new MediaFile();
        $this->mediaFileRepository->method('findByFileSizeBytes')
            ->willReturn([$mediaFile1, $mediaFile2]);

        $result = $method->invoke($this->service, ['hash' => 'nohash']);

        $this->assertNull($result);
    }
}
