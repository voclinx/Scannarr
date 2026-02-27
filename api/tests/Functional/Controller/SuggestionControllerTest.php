<?php

namespace App\Tests\Functional\Controller;

use App\Entity\MediaFile;
use App\Entity\Movie;
use App\Entity\MovieFile;
use App\Entity\TorrentStat;
use App\Entity\Volume;
use App\Enum\TorrentStatus;
use App\Enum\VolumeStatus;
use App\Enum\VolumeType;
use App\Tests\AbstractApiTestCase;
use DateTimeImmutable;

class SuggestionControllerTest extends AbstractApiTestCase
{
    private function createVolume(string $name = 'Test Volume'): Volume
    {
        $volume = new Volume();
        $volume->setName($name);
        $volume->setPath(sys_get_temp_dir() . '/' . uniqid('vol_', true));
        $volume->setHostPath('/mnt/host/' . uniqid('vol_', true));
        $volume->setType(VolumeType::LOCAL);
        $volume->setStatus(VolumeStatus::ACTIVE);
        $this->em->persist($volume);
        $this->em->flush();

        return $volume;
    }

    private function createMovie(string $title, ?int $year = null): Movie
    {
        $movie = new Movie();
        $movie->setTitle($title);
        if ($year !== null) {
            $movie->setYear($year);
        }
        $this->em->persist($movie);
        $this->em->flush();

        return $movie;
    }

    private function createMediaFile(
        Volume $volume,
        string $fileName = 'movie.mkv',
        int $sizeBytes = 1500000000,
        int $hardlinkCount = 1,
    ): MediaFile {
        $mediaFile = new MediaFile();
        $mediaFile->setVolume($volume);
        $mediaFile->setFilePath('movies/' . $fileName);
        $mediaFile->setFileName($fileName);
        $mediaFile->setFileSizeBytes($sizeBytes);
        $mediaFile->setHardlinkCount($hardlinkCount);
        $this->em->persist($mediaFile);
        $this->em->flush();

        return $mediaFile;
    }

    private function createMovieFile(Movie $movie, MediaFile $mediaFile): MovieFile
    {
        $movieFile = new MovieFile();
        $movieFile->setMovie($movie);
        $movieFile->setMediaFile($mediaFile);
        $movieFile->setMatchedBy('filename');
        $this->em->persist($movieFile);
        $this->em->flush();

        return $movieFile;
    }

    private function createTorrentStat(
        MediaFile $mediaFile,
        string $hash = 'abc123',
        string $tracker = 'tracker-a.com',
        string $ratio = '1.5000',
        int $seedTimeSeconds = 3600,
        TorrentStatus $status = TorrentStatus::SEEDING,
    ): TorrentStat {
        $stat = new TorrentStat();
        $stat->setMediaFile($mediaFile);
        $stat->setTorrentHash($hash);
        $stat->setTorrentName('Test Torrent');
        $stat->setTrackerDomain($tracker);
        $stat->setRatio($ratio);
        $stat->setSeedTimeSeconds($seedTimeSeconds);
        $stat->setUploadedBytes(1500000000);
        $stat->setDownloadedBytes(1000000000);
        $stat->setSizeBytes(1000000000);
        $stat->setStatus($status);
        $stat->setAddedAt(new DateTimeImmutable('-30 days'));
        $stat->setLastActivityAt(new DateTimeImmutable());
        $this->em->persist($stat);
        $this->em->flush();

        return $stat;
    }

    // -----------------------------------------------------------------------
    // GET /api/v1/suggestions
    // -----------------------------------------------------------------------

    public function testGetSuggestionsReturnsRawData(): void
    {
        $user = $this->createAdvancedUser();
        $this->authenticateAs($user);

        $volume = $this->createVolume();
        $movie = $this->createMovie('Test Movie', 2020);
        $mediaFile = $this->createMediaFile($volume, 'test.mkv', 2000000000);
        $this->createMovieFile($movie, $mediaFile);
        $this->createTorrentStat($mediaFile, 'hash1', 'tracker-a.com', '1.5000', 86400);

        $this->em->clear();

        $this->apiGet('/api/v1/suggestions');
        $this->assertResponseStatusCode(200);

        $response = $this->getResponseData();
        $this->assertArrayHasKey('data', $response);
        $this->assertArrayHasKey('meta', $response);
        $this->assertNotEmpty($response['data']);

        $item = $response['data'][0];
        $this->assertArrayHasKey('movie', $item);
        $this->assertArrayHasKey('files', $item);
        $this->assertArrayHasKey('seeding_status', $item);
        $this->assertArrayHasKey('best_ratio', $item);
        $this->assertArrayHasKey('worst_ratio', $item);
        $this->assertArrayHasKey('cross_seed_count', $item);
        $this->assertArrayHasKey('blocked_by_tracker_rules', $item);

        // No score fields — scoring is frontend-only
        $this->assertArrayNotHasKey('score', $item);
        $this->assertArrayNotHasKey('score_breakdown', $item);

        $this->assertEquals('Test Movie', $item['movie']['title']);
        $this->assertEquals('seeding', $item['seeding_status']);

        // Verify file has torrents
        $file = $item['files'][0];
        $this->assertArrayHasKey('torrents', $file);
        $this->assertCount(1, $file['torrents']);
        $this->assertEquals('hash1', $file['torrents'][0]['torrent_hash']);
    }

    public function testGetSuggestionsFilterOrphansOnly(): void
    {
        $user = $this->createAdvancedUser();
        $this->authenticateAs($user);

        $volume = $this->createVolume();

        // Movie with torrent (seeding)
        $movieSeeding = $this->createMovie('Seeding Movie', 2020);
        $fileSeed = $this->createMediaFile($volume, 'seeding.mkv', 1000000000);
        $this->createMovieFile($movieSeeding, $fileSeed);
        $this->createTorrentStat($fileSeed, 'seedhash', 'tracker.com');

        // Movie without torrent (orphan)
        $movieOrphan = $this->createMovie('Orphan Movie', 2019);
        $fileOrphan = $this->createMediaFile($volume, 'orphan.mkv', 500000000);
        $this->createMovieFile($movieOrphan, $fileOrphan);

        $this->em->clear();

        $this->apiGet('/api/v1/suggestions', ['seeding_status' => 'orphans_only']);
        $this->assertResponseStatusCode(200);

        $response = $this->getResponseData();
        $this->assertNotEmpty($response['data']);

        foreach ($response['data'] as $item) {
            $this->assertEquals('orphan', $item['seeding_status']);
        }
    }

    public function testGetSuggestionsExcludeProtected(): void
    {
        $user = $this->createAdvancedUser();
        $this->authenticateAs($user);

        $volume = $this->createVolume();

        // Protected movie
        $movieProtected = $this->createMovie('Protected Movie', 2020);
        $movieProtected->setIsProtected(true);
        $fileProtected = $this->createMediaFile($volume, 'protected.mkv');
        $this->createMovieFile($movieProtected, $fileProtected);
        $this->em->flush();

        // Normal movie
        $movieNormal = $this->createMovie('Normal Movie', 2019);
        $fileNormal = $this->createMediaFile($volume, 'normal.mkv');
        $this->createMovieFile($movieNormal, $fileNormal);

        $this->em->clear();

        // With exclude_protected=true (default)
        $this->apiGet('/api/v1/suggestions');
        $this->assertResponseStatusCode(200);

        $response = $this->getResponseData();
        $movieTitles = array_column(array_column($response['data'], 'movie'), 'title');
        $this->assertNotContains('Protected Movie', $movieTitles);
        $this->assertContains('Normal Movie', $movieTitles);
    }

    public function testGetSuggestionsFilterByVolume(): void
    {
        $user = $this->createAdvancedUser();
        $this->authenticateAs($user);

        $volumeA = $this->createVolume('Volume A');
        $volumeB = $this->createVolume('Volume B');

        $movie = $this->createMovie('Multi Volume Movie', 2020);
        $fileA = $this->createMediaFile($volumeA, 'fileA.mkv');
        $fileB = $this->createMediaFile($volumeB, 'fileB.mkv');
        $this->createMovieFile($movie, $fileA);
        $this->createMovieFile($movie, $fileB);

        $this->em->clear();

        $this->apiGet('/api/v1/suggestions', ['volume_id' => (string)$volumeA->getId()]);
        $this->assertResponseStatusCode(200);

        $response = $this->getResponseData();
        if (!empty($response['data'])) {
            foreach ($response['data'] as $item) {
                foreach ($item['files'] as $file) {
                    $this->assertEquals((string)$volumeA->getId(), $file['volume_id']);
                }
            }
        }
    }

    public function testGetSuggestionsMeta(): void
    {
        $user = $this->createAdvancedUser();
        $this->authenticateAs($user);

        $this->apiGet('/api/v1/suggestions');
        $this->assertResponseStatusCode(200);

        $response = $this->getResponseData();
        $meta = $response['meta'];
        $this->assertArrayHasKey('total', $meta);
        $this->assertArrayHasKey('page', $meta);
        $this->assertArrayHasKey('per_page', $meta);
        $this->assertArrayHasKey('total_pages', $meta);
        $this->assertArrayHasKey('summary', $meta);
        $this->assertArrayHasKey('total_selectable_size', $meta['summary']);
        $this->assertArrayHasKey('trackers_detected', $meta['summary']);
    }

    public function testGetSuggestionsAsRegularUserForbidden(): void
    {
        $user = $this->createUser();
        $this->authenticateAs($user);

        $this->apiGet('/api/v1/suggestions');
        $this->assertResponseStatusCode(403);
    }

    // -----------------------------------------------------------------------
    // POST /api/v1/suggestions/batch-delete
    // -----------------------------------------------------------------------

    public function testBatchDeleteCreatesScheduledDeletion(): void
    {
        $user = $this->createAdvancedUser();
        $this->authenticateAs($user);

        $volume = $this->createVolume();
        $movie = $this->createMovie('To Delete', 2020);
        $mediaFile = $this->createMediaFile($volume, 'delete.mkv');
        $this->createMovieFile($movie, $mediaFile);

        $this->apiPost('/api/v1/suggestions/batch-delete', [
            'items' => [
                ['movie_id' => (string)$movie->getId(), 'file_ids' => [(string)$mediaFile->getId()]],
            ],
            'options' => ['delete_radarr_reference' => false],
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [200, 202]);

        $response = $this->getResponseData();
        $this->assertArrayHasKey('data', $response);
        $this->assertArrayHasKey('deletion_id', $response['data']);
        $this->assertArrayHasKey('status', $response['data']);
        $this->assertEquals(1, $response['data']['items_count']);
    }

    public function testBatchDeleteEmptyItems(): void
    {
        $user = $this->createAdvancedUser();
        $this->authenticateAs($user);

        $this->apiPost('/api/v1/suggestions/batch-delete', [
            'items' => [],
        ]);

        $this->assertResponseStatusCode(422);
    }

    // -----------------------------------------------------------------------
    // POST /api/v1/suggestions/batch-schedule
    // -----------------------------------------------------------------------

    public function testBatchScheduleCreatesScheduledDeletion(): void
    {
        $user = $this->createAdvancedUser();
        $this->authenticateAs($user);

        $volume = $this->createVolume();
        $movie = $this->createMovie('To Schedule', 2020);
        $mediaFile = $this->createMediaFile($volume, 'schedule.mkv');
        $this->createMovieFile($movie, $mediaFile);

        $this->apiPost('/api/v1/suggestions/batch-schedule', [
            'items' => [
                ['movie_id' => (string)$movie->getId(), 'file_ids' => [(string)$mediaFile->getId()]],
            ],
            'scheduled_date' => '2026-12-31',
            'options' => ['disable_radarr_auto_search' => true],
        ]);

        $this->assertResponseStatusCode(201);

        $response = $this->getResponseData();
        $this->assertArrayHasKey('data', $response);
        $this->assertArrayHasKey('deletion_id', $response['data']);
        $this->assertEquals('2026-12-31', $response['data']['scheduled_date']);
        $this->assertEquals('pending', $response['data']['status']);
        $this->assertEquals(1, $response['data']['items_count']);
    }

    public function testBatchScheduleMissingDate(): void
    {
        $user = $this->createAdvancedUser();
        $this->authenticateAs($user);

        $this->apiPost('/api/v1/suggestions/batch-schedule', [
            'items' => [
                ['movie_id' => '019c0000-0000-0000-0000-000000000000', 'file_ids' => []],
            ],
        ]);

        $this->assertResponseStatusCode(422);
    }

    public function testBatchSchedulePastDate(): void
    {
        $user = $this->createAdvancedUser();
        $this->authenticateAs($user);

        $this->apiPost('/api/v1/suggestions/batch-schedule', [
            'items' => [
                ['movie_id' => '019c0000-0000-0000-0000-000000000000', 'file_ids' => []],
            ],
            'scheduled_date' => '2020-01-01',
        ]);

        $this->assertResponseStatusCode(422);
    }
}
