<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\ActivityLog;
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
use Symfony\Component\Uid\Uuid;

class MovieControllerTest extends AbstractApiTestCase
{
    /**
     * Helper: create a Movie entity directly via EntityManager.
     */
    private function createMovie(string $title, ?int $year = null, ?int $tmdbId = null): Movie
    {
        $movie = new Movie();
        $movie->setTitle($title);

        if ($year !== null) {
            $movie->setYear($year);
        }

        if ($tmdbId !== null) {
            $movie->setTmdbId($tmdbId);
        }

        $this->em->persist($movie);
        $this->em->flush();

        return $movie;
    }

    /**
     * Helper: create a Volume entity.
     */
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

    /**
     * Helper: create a MediaFile entity linked to a Volume.
     */
    private function createMediaFile(Volume $volume, string $fileName = 'movie.mkv', int $sizeBytes = 1500000000): MediaFile
    {
        $mediaFile = new MediaFile();
        $mediaFile->setVolume($volume);
        $mediaFile->setFilePath('movies/' . $fileName);
        $mediaFile->setFileName($fileName);
        $mediaFile->setFileSizeBytes($sizeBytes);
        $this->em->persist($mediaFile);
        $this->em->flush();

        return $mediaFile;
    }

    /**
     * Helper: create a MovieFile linking a Movie to a MediaFile.
     */
    private function createMovieFile(Movie $movie, MediaFile $mediaFile, string $matchedBy = 'filename'): MovieFile
    {
        $movieFile = new MovieFile();
        $movieFile->setMovie($movie);
        $movieFile->setMediaFile($mediaFile);
        $movieFile->setMatchedBy($matchedBy);
        $movieFile->setConfidence('0.95');
        $this->em->persist($movieFile);
        $this->em->flush();

        return $movieFile;
    }

    // -----------------------------------------------------------------------
    // TEST-MOVIE-001 : Lister les films - pagination
    // -----------------------------------------------------------------------

    /**
     * @testdox TEST-MOVIE-001 Lister les films - pagination
     */
    public function testListMoviesPagination(): void
    {
        $user = $this->createUser();
        $this->authenticateAs($user);

        // Create 15 movies
        for ($i = 1; $i <= 15; ++$i) {
            $this->createMovie("Movie {$i}", 2020 + ($i % 5), 100000 + $i);
        }

        $this->apiGet('/api/v1/movies', ['page' => 1, 'limit' => 10]);

        $this->assertResponseStatusCode(200);

        $response = $this->getResponseData();
        $this->assertArrayHasKey('data', $response);
        $this->assertArrayHasKey('meta', $response);
        $this->assertCount(10, $response['data']);
        $this->assertEquals(15, $response['meta']['total']);
        $this->assertEquals(1, $response['meta']['page']);
        $this->assertEquals(10, $response['meta']['limit']);
        $this->assertEquals(2, $response['meta']['total_pages']);
    }

    // -----------------------------------------------------------------------
    // TEST-MOVIE-002 : Lister les films - recherche par titre
    // -----------------------------------------------------------------------

    /**
     * @testdox TEST-MOVIE-002 Lister les films - recherche par titre
     */
    public function testListMoviesSearchByTitle(): void
    {
        $user = $this->createUser();
        $this->authenticateAs($user);

        $this->createMovie('Inception', 2010, 27205);
        $this->createMovie('The Dark Knight', 2008, 155);
        $this->createMovie('Interstellar', 2014, 157336);

        $this->apiGet('/api/v1/movies', ['search' => 'inception']);

        $this->assertResponseStatusCode(200);

        $response = $this->getResponseData();
        $this->assertArrayHasKey('data', $response);
        $this->assertGreaterThanOrEqual(1, count($response['data']));

        // Every returned movie should contain "inception" in the title (case insensitive)
        foreach ($response['data'] as $movie) {
            $this->assertStringContainsStringIgnoringCase('inception', $movie['title']);
        }
    }

    // -----------------------------------------------------------------------
    // TEST-MOVIE-003 : Lister les films - tri par année descendant
    // -----------------------------------------------------------------------

    /**
     * @testdox TEST-MOVIE-003 Lister les films - tri par année descendant
     */
    public function testListMoviesSortByYearDesc(): void
    {
        $user = $this->createUser();
        $this->authenticateAs($user);

        $this->createMovie('Old Movie', 1990, 90001);
        $this->createMovie('Mid Movie', 2010, 90002);
        $this->createMovie('New Movie', 2024, 90003);

        $this->apiGet('/api/v1/movies', ['sort' => 'year', 'order' => 'desc']);

        $this->assertResponseStatusCode(200);

        $response = $this->getResponseData();
        $this->assertArrayHasKey('data', $response);
        $this->assertGreaterThanOrEqual(3, count($response['data']));

        // Verify descending year order
        $years = array_map(fn (array $m) => $m['year'], $response['data']);
        $sortedYears = $years;
        rsort($sortedYears);
        $this->assertEquals($sortedYears, $years, 'Movies should be sorted by year descending');
    }

    // -----------------------------------------------------------------------
    // TEST-MOVIE-004 : Détail d'un film - inclut les fichiers liés
    // -----------------------------------------------------------------------

    /**
     * @testdox TEST-MOVIE-004 Détail d'un film - inclut les fichiers liés
     */
    public function testMovieDetailIncludesFiles(): void
    {
        $user = $this->createUser();
        $this->authenticateAs($user);

        $movie = $this->createMovie('Inception', 2010, 27205);
        $volume = $this->createVolume('Movies Volume');
        $mediaFile = $this->createMediaFile($volume, 'Inception.2010.1080p.mkv', 2000000000);
        $this->createMovieFile($movie, $mediaFile);

        $movieId = (string)$movie->getId();

        // Clear identity map to force fresh load from DB (avoids stale lazy collections)
        $this->em->clear();

        $this->apiGet("/api/v1/movies/{$movieId}");

        $this->assertResponseStatusCode(200);

        $response = $this->getResponseData();
        $this->assertArrayHasKey('data', $response);

        $movieData = $response['data'];
        $this->assertEquals('Inception', $movieData['title']);
        $this->assertEquals(2010, $movieData['year']);
        $this->assertArrayHasKey('files', $movieData);
        $this->assertIsArray($movieData['files']);
        $this->assertCount(1, $movieData['files']);

        $file = $movieData['files'][0];
        $this->assertEquals('Inception.2010.1080p.mkv', $file['file_name']);
        $this->assertEquals(2000000000, $file['file_size_bytes']);
        $this->assertEquals((string)$mediaFile->getId(), $file['id']);
        $this->assertEquals((string)$volume->getId(), $file['volume_id']);
        $this->assertArrayHasKey('matched_by', $file);
        $this->assertEquals('filename', $file['matched_by']);
    }

    // -----------------------------------------------------------------------
    // TEST-MOVIE-005 : Suppression globale - à la carte
    // -----------------------------------------------------------------------

    /**
     * @testdox TEST-MOVIE-005 Suppression globale - à la carte
     */
    public function testGlobalDeletionALaCarte(): void
    {
        $advancedUser = $this->createAdvancedUser();
        $this->authenticateAs($advancedUser);

        $movie = $this->createMovie('To Delete', 2020, 99999);
        $volume = $this->createVolume('Delete Volume');
        $mediaFile = $this->createMediaFile($volume, 'ToDelete.mkv', 500000000);
        $this->createMovieFile($movie, $mediaFile);

        $movieId = (string)$movie->getId();
        $mediaFileId = (string)$mediaFile->getId();

        $this->apiDelete("/api/v1/movies/{$movieId}", [
            'file_ids' => [$mediaFileId],
            'delete_radarr_reference' => false,
        ]);

        // Async watcher-based deletion: returns 202 (WAITING_WATCHER/EXECUTING) or 200 (COMPLETED)
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [200, 202], "Expected 200 or 202, got {$statusCode}");

        $response = $this->getResponseData();
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('Deletion initiated', $response['data']['message']);
        $this->assertArrayHasKey('deletion_id', $response['data']);
        $this->assertArrayHasKey('status', $response['data']);

        // In the async model, the MediaFile is NOT removed yet (that happens when the
        // watcher completes the physical deletion and sends files.delete.completed).
        // We verify the deletion was properly created instead.
        $this->assertNotEmpty($response['data']['deletion_id']);

        // Verify an activity log was created
        $logs = $this->em->getRepository(ActivityLog::class)->findBy([
            'action' => 'movie.deleted',
        ]);
        $this->assertNotEmpty($logs, 'An activity log should be created for movie deletion');

        $log = $logs[0];
        $this->assertEquals('movie', $log->getEntityType());
        $this->assertEquals($movie->getId(), $log->getEntityId());
    }

    // -----------------------------------------------------------------------
    // TEST-MOVIE-006 : Suppression globale - film inexistant
    // -----------------------------------------------------------------------

    /**
     * @testdox TEST-MOVIE-006 Suppression globale - film inexistant
     */
    public function testGlobalDeletionMovieNotFound(): void
    {
        $advancedUser = $this->createAdvancedUser();
        $this->authenticateAs($advancedUser);

        $fakeId = Uuid::v4();

        $this->apiDelete("/api/v1/movies/{$fakeId}", [
            'file_ids' => [],
            'delete_radarr_reference' => false,
        ]);

        $this->assertResponseStatusCode(404);

        $response = $this->getResponseData();
        $this->assertArrayHasKey('error', $response);
        $this->assertEquals(404, $response['error']['code']);
        $this->assertStringContainsString('not found', $response['error']['message']);
    }

    // -----------------------------------------------------------------------
    // TEST-MOVIE-007 : Suppression - file_ids n'appartenant pas au film
    // -----------------------------------------------------------------------

    /**
     * @testdox TEST-MOVIE-007 Suppression avec file_ids invalides (autre film)
     */
    public function testGlobalDeletionRejectsFileIdsFromAnotherMovie(): void
    {
        $advancedUser = $this->createAdvancedUser();
        $this->authenticateAs($advancedUser);

        // Create two movies with their own files
        $movieA = $this->createMovie('Movie A', 2020, 80001);
        $movieB = $this->createMovie('Movie B', 2021, 80002);
        $volume = $this->createVolume('Shared Volume');

        $mediaFileA = $this->createMediaFile($volume, 'MovieA.mkv', 500000000);
        $mediaFileB = $this->createMediaFile($volume, 'MovieB.mkv', 600000000);

        $this->createMovieFile($movieA, $mediaFileA);
        $this->createMovieFile($movieB, $mediaFileB);

        $movieAId = (string)$movieA->getId();
        $mediaFileBId = (string)$mediaFileB->getId();

        // Try to delete Movie A but pass Movie B's file_id
        $this->apiDelete("/api/v1/movies/{$movieAId}", [
            'file_ids' => [$mediaFileBId],
            'delete_radarr_reference' => false,
        ]);

        $this->assertResponseStatusCode(400);

        $response = $this->getResponseData();
        $this->assertArrayHasKey('error', $response);
        $this->assertEquals(400, $response['error']['code']);
        $this->assertStringContainsString('do not belong', $response['error']['message']);
        $this->assertContains($mediaFileBId, $response['error']['invalid_ids']);
    }

    /**
     * @testdox TEST-MOVIE-007b Suppression avec file_ids mixtes (valide + invalide)
     */
    public function testGlobalDeletionRejectsMixedFileIds(): void
    {
        $advancedUser = $this->createAdvancedUser();
        $this->authenticateAs($advancedUser);

        $movieA = $this->createMovie('Movie A', 2020, 80003);
        $movieB = $this->createMovie('Movie B', 2021, 80004);
        $volume = $this->createVolume('Shared Volume 2');

        $mediaFileA = $this->createMediaFile($volume, 'MovieA2.mkv', 500000000);
        $mediaFileB = $this->createMediaFile($volume, 'MovieB2.mkv', 600000000);

        $this->createMovieFile($movieA, $mediaFileA);
        $this->createMovieFile($movieB, $mediaFileB);

        $movieAId = (string)$movieA->getId();
        $mediaFileAId = (string)$mediaFileA->getId();
        $mediaFileBId = (string)$mediaFileB->getId();

        // Pass one valid and one invalid file_id
        $this->apiDelete("/api/v1/movies/{$movieAId}", [
            'file_ids' => [$mediaFileAId, $mediaFileBId],
            'delete_radarr_reference' => false,
        ]);

        $this->assertResponseStatusCode(400);

        $response = $this->getResponseData();
        $this->assertArrayHasKey('error', $response);
        // Only the invalid one should be reported
        $this->assertContains($mediaFileBId, $response['error']['invalid_ids']);
        $this->assertNotContains($mediaFileAId, $response['error']['invalid_ids']);
    }

    // -----------------------------------------------------------------------
    // TEST-MOVIE-008 : Sync Radarr - déclenchement
    // -----------------------------------------------------------------------

    /**
     * @testdox TEST-MOVIE-008 Sync Radarr - déclenchement
     */
    public function testSyncRadarrTriggered(): void
    {
        $admin = $this->createAdmin();
        $this->authenticateAs($admin);

        $this->apiPost('/api/v1/movies/sync');

        $this->assertResponseStatusCode(202);

        $response = $this->getResponseData();
        $this->assertArrayHasKey('data', $response);
        $this->assertArrayHasKey('message', $response['data']);
        $this->assertStringContainsString('sync', strtolower((string)$response['data']['message']));
    }

    // -----------------------------------------------------------------------
    // TEST-MOVIE-009 : Protection d'un film
    // -----------------------------------------------------------------------

    /**
     * @testdox TEST-MOVIE-009 Protection d'un film - toggle
     */
    public function testProtectMovieEnable(): void
    {
        $advancedUser = $this->createAdvancedUser();
        $this->authenticateAs($advancedUser);

        $movie = $this->createMovie('Protect Me', 2020, 77001);
        $movieId = (string)$movie->getId();

        $this->apiPut("/api/v1/movies/{$movieId}/protect", [
            'is_protected' => true,
        ]);

        $this->assertResponseStatusCode(200);

        $response = $this->getResponseData();
        $this->assertTrue($response['data']['is_protected']);
        $this->assertEquals($movieId, $response['data']['id']);
    }

    /**
     * @testdox TEST-MOVIE-009c Protection - désactiver
     */
    public function testProtectMovieDisable(): void
    {
        $advancedUser = $this->createAdvancedUser();
        $this->authenticateAs($advancedUser);

        $movie = $this->createMovie('Unprotect Me', 2020, 77002);
        $movie->setIsProtected(true);
        $this->em->flush();

        $movieId = (string)$movie->getId();

        $this->apiPut("/api/v1/movies/{$movieId}/protect", [
            'is_protected' => false,
        ]);

        $this->assertResponseStatusCode(200);
        $response = $this->getResponseData();
        $this->assertFalse($response['data']['is_protected']);
    }

    /**
     * @testdox TEST-MOVIE-009b Protection - film inexistant
     */
    public function testProtectMovieNotFound(): void
    {
        $advancedUser = $this->createAdvancedUser();
        $this->authenticateAs($advancedUser);

        $fakeId = Uuid::v4();

        $this->apiPut("/api/v1/movies/{$fakeId}/protect", [
            'is_protected' => true,
        ]);

        $this->assertResponseStatusCode(404);
    }

    // -----------------------------------------------------------------------
    // TEST-MOVIE-010 : Liste enrichie avec champs V1.5
    // -----------------------------------------------------------------------

    /**
     * @testdox TEST-MOVIE-010 Liste enrichie avec champs V1.5
     */
    public function testListMoviesReturnsEnrichedFields(): void
    {
        $user = $this->createUser();
        $this->authenticateAs($user);

        $movie = $this->createMovie('Enriched Movie', 2020, 88001);
        $volume = $this->createVolume('Enriched Volume');
        $mediaFile = $this->createMediaFile($volume, 'enriched.mkv', 2000000000);
        $this->createMovieFile($movie, $mediaFile);

        // Create torrent stat
        $stat = new TorrentStat();
        $stat->setMediaFile($mediaFile);
        $stat->setTorrentHash('enrichhash001');
        $stat->setTorrentName('Enriched.Movie.2020.1080p');
        $stat->setTrackerDomain('tracker-a.com');
        $stat->setRatio('1.5000');
        $stat->setSeedTimeSeconds(86400);
        $stat->setUploadedBytes(3000000000);
        $stat->setDownloadedBytes(2000000000);
        $stat->setSizeBytes(2000000000);
        $stat->setStatus(TorrentStatus::SEEDING);
        $this->em->persist($stat);
        $this->em->flush();

        $this->em->clear();

        $this->apiGet('/api/v1/movies');
        $this->assertResponseStatusCode(200);

        $response = $this->getResponseData();
        $this->assertNotEmpty($response['data']);

        // Find our movie in the list
        $found = null;
        foreach ($response['data'] as $m) {
            if ($m['title'] === 'Enriched Movie') {
                $found = $m;
                break;
            }
        }

        $this->assertNotNull($found, 'Movie should be in the list');
        $this->assertArrayHasKey('is_protected', $found);
        $this->assertArrayHasKey('multi_file_badge', $found);
        $this->assertArrayHasKey('best_ratio', $found);
        $this->assertArrayHasKey('worst_ratio', $found);
        $this->assertArrayHasKey('total_seed_time_max_seconds', $found);
        $this->assertArrayHasKey('seeding_status', $found);
        $this->assertArrayHasKey('cross_seed_count', $found);

        $this->assertFalse($found['is_protected']);
        $this->assertFalse($found['multi_file_badge']);
        $this->assertEquals(1.5, $found['best_ratio']);
        $this->assertEquals(1.5, $found['worst_ratio']);
        $this->assertEquals(86400, $found['total_seed_time_max_seconds']);
        $this->assertEquals('seeding', $found['seeding_status']);
        $this->assertEquals(1, $found['cross_seed_count']);
    }

    // -----------------------------------------------------------------------
    // TEST-MOVIE-011 : Détail enrichi avec torrents
    // -----------------------------------------------------------------------

    /**
     * @testdox TEST-MOVIE-011 Détail enrichi avec torrents
     */
    public function testMovieDetailIncludesTorrentStats(): void
    {
        $user = $this->createUser();
        $this->authenticateAs($user);

        $movie = $this->createMovie('Torrent Detail', 2021, 88002);
        $volume = $this->createVolume('Torrent Volume');
        $mediaFile = $this->createMediaFile($volume, 'torrent_detail.mkv', 3000000000);
        $this->createMovieFile($movie, $mediaFile);

        $stat = new TorrentStat();
        $stat->setMediaFile($mediaFile);
        $stat->setTorrentHash('detailhash001');
        $stat->setTorrentName('Torrent.Detail.2021');
        $stat->setTrackerDomain('tracker-b.org');
        $stat->setRatio('2.0000');
        $stat->setSeedTimeSeconds(172800);
        $stat->setUploadedBytes(6000000000);
        $stat->setDownloadedBytes(3000000000);
        $stat->setSizeBytes(3000000000);
        $stat->setStatus(TorrentStatus::SEEDING);
        $stat->setAddedAt(new DateTimeImmutable('-7 days'));
        $stat->setLastActivityAt(new DateTimeImmutable());
        $this->em->persist($stat);
        $this->em->flush();

        $movieId = (string)$movie->getId();
        $this->em->clear();

        $this->apiGet("/api/v1/movies/{$movieId}");
        $this->assertResponseStatusCode(200);

        $response = $this->getResponseData();
        $movieData = $response['data'];

        $this->assertArrayHasKey('is_protected', $movieData);
        $this->assertFalse($movieData['is_protected']);

        $file = $movieData['files'][0];
        $this->assertArrayHasKey('torrents', $file);
        $this->assertArrayHasKey('cross_seed_count', $file);
        $this->assertArrayHasKey('is_protected', $file);
        $this->assertCount(1, $file['torrents']);
        $this->assertEquals(1, $file['cross_seed_count']);

        $torrent = $file['torrents'][0];
        $this->assertEquals('detailhash001', $torrent['torrent_hash']);
        $this->assertEquals('Torrent.Detail.2021', $torrent['torrent_name']);
        $this->assertEquals('tracker-b.org', $torrent['tracker_domain']);
        $this->assertEquals(2.0, $torrent['ratio']);
        $this->assertEquals(172800, $torrent['seed_time_seconds']);
        $this->assertEquals('seeding', $torrent['status']);
        $this->assertArrayHasKey('added_at', $torrent);
        $this->assertArrayHasKey('last_activity_at', $torrent);
    }
}
