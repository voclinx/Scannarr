<?php

namespace App\Tests\Functional\Controller;

use App\Entity\ActivityLog;
use App\Entity\MediaFile;
use App\Entity\Movie;
use App\Entity\MovieFile;
use App\Entity\Volume;
use App\Enum\VolumeStatus;
use App\Enum\VolumeType;
use App\Tests\AbstractApiTestCase;
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
        for ($i = 1; $i <= 15; $i++) {
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
        $years = array_map(fn(array $m) => $m['year'], $response['data']);
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

        $movieId = (string) $movie->getId();

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
        $this->assertEquals((string) $mediaFile->getId(), $file['id']);
        $this->assertEquals((string) $volume->getId(), $file['volume_id']);
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

        $movieId = (string) $movie->getId();
        $mediaFileId = (string) $mediaFile->getId();

        $this->apiDelete("/api/v1/movies/{$movieId}", [
            'file_ids' => [$mediaFileId],
            'delete_radarr_reference' => false,
        ]);

        $this->assertResponseStatusCode(200);

        $response = $this->getResponseData();
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('Movie deletion completed', $response['data']['message']);
        $this->assertArrayHasKey('files_deleted', $response['data']);
        $this->assertArrayHasKey('radarr_dereferenced', $response['data']);
        $this->assertFalse($response['data']['radarr_dereferenced']);

        // Verify the MediaFile is removed from DB
        $this->em->clear();
        $deletedFile = $this->em->getRepository(MediaFile::class)->find($mediaFileId);
        $this->assertNull($deletedFile, 'MediaFile should be removed from database after deletion');

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
    // TEST-MOVIE-007 : Sync Radarr - déclenchement
    // -----------------------------------------------------------------------

    /**
     * @testdox TEST-MOVIE-007 Sync Radarr - déclenchement
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
        $this->assertStringContainsString('sync', strtolower($response['data']['message']));
    }
}
