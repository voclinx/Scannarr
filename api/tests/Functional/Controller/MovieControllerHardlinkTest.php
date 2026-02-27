<?php

namespace App\Tests\Functional\Controller;

use App\Entity\MediaFile;
use App\Entity\Movie;
use App\Entity\MovieFile;
use App\Entity\ScheduledDeletion;
use App\Entity\Volume;
use App\Enum\VolumeStatus;
use App\Enum\VolumeType;
use App\Tests\AbstractApiTestCase;

class MovieControllerHardlinkTest extends AbstractApiTestCase
{
    private function createMovie(string $title): Movie
    {
        $movie = new Movie();
        $movie->setTitle($title);
        $this->em->persist($movie);
        $this->em->flush();

        return $movie;
    }

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

    private function createMediaFile(
        Volume $volume,
        string $fileName = 'movie.mkv',
        bool $isLinkedMediaPlayer = false,
    ): MediaFile {
        $mediaFile = new MediaFile();
        $mediaFile->setVolume($volume);
        $mediaFile->setFilePath('movies/' . $fileName);
        $mediaFile->setFileName($fileName);
        $mediaFile->setFileSizeBytes(1500000000);
        $mediaFile->setIsLinkedMediaPlayer($isLinkedMediaPlayer);
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
        $movieFile->setConfidence('0.95');
        $this->em->persist($movieFile);
        $this->em->flush();

        return $movieFile;
    }

    /**
     * @testdox DELETE with replacement_map creates a ScheduledDeletion and returns deletion_id
     */
    public function testDeleteWithReplacementMapCreatesScheduledDeletion(): void
    {
        $user = $this->createAdvancedUser();
        $this->authenticateAs($user);

        $movie = $this->createMovie('Test Movie');
        $volume = $this->createVolume();

        // File 1: currently served by media player
        $playerFile = $this->createMediaFile($volume, 'movie.1080p.mkv', isLinkedMediaPlayer: true);
        // File 2: replacement (better quality)
        $replacementFile = $this->createMediaFile($volume, 'movie.2160p.mkv', isLinkedMediaPlayer: false);

        $this->createMovieFile($movie, $playerFile);
        $this->createMovieFile($movie, $replacementFile);

        $movieId = (string)$movie->getId();
        $playerFileId = (string)$playerFile->getId();
        $replacementFileId = (string)$replacementFile->getId();

        $this->apiDelete("/api/v1/movies/{$movieId}", [
            'file_ids' => [$playerFileId],
            'replacement_map' => [$playerFileId => $replacementFileId],
        ]);

        // Watcher may be offline → 202 ACCEPTED is acceptable; 200 OK too
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [200, 202], "Expected 200 or 202, got $statusCode");

        $response = $this->getResponseData();
        $this->assertArrayHasKey('data', $response);
        $this->assertArrayHasKey('deletion_id', $response['data']);

        // Verify ScheduledDeletion was created in DB
        $deletionId = $response['data']['deletion_id'];
        $deletion = $this->em->getRepository(ScheduledDeletion::class)->find($deletionId);
        $this->assertNotNull($deletion, 'ScheduledDeletion should be persisted in DB');
    }

    /**
     * @testdox DELETE with replacement_map where oldFileId is not a media player file returns 400
     */
    public function testDeleteWithReplacementMapInvalidFileRejected(): void
    {
        $user = $this->createAdvancedUser();
        $this->authenticateAs($user);

        $movie = $this->createMovie('Test Movie');
        $volume = $this->createVolume();

        // File 1: NOT a media player file
        $normalFile = $this->createMediaFile($volume, 'movie.1080p.mkv', isLinkedMediaPlayer: false);
        $replacementFile = $this->createMediaFile($volume, 'movie.2160p.mkv', isLinkedMediaPlayer: false);

        $this->createMovieFile($movie, $normalFile);
        $this->createMovieFile($movie, $replacementFile);

        $movieId = (string)$movie->getId();
        $normalFileId = (string)$normalFile->getId();
        $replacementFileId = (string)$replacementFile->getId();

        $this->apiDelete("/api/v1/movies/{$movieId}", [
            'file_ids' => [$normalFileId],
            'replacement_map' => [$normalFileId => $replacementFileId],
        ]);

        $this->assertResponseStatusCode(400);

        $response = $this->getResponseData();
        $this->assertArrayHasKey('error', $response);
        $this->assertStringContainsString('not a media player file', $response['error']['message']);
    }

    /**
     * @testdox DELETE without replacement_map follows the standard deletion flow
     */
    public function testDeleteWithoutReplacementMapIsUnchanged(): void
    {
        $user = $this->createAdvancedUser();
        $this->authenticateAs($user);

        $movie = $this->createMovie('Standard Delete Movie');
        $volume = $this->createVolume();
        $mediaFile = $this->createMediaFile($volume, 'movie.mkv');
        $this->createMovieFile($movie, $mediaFile);

        $movieId = (string)$movie->getId();
        $mediaFileId = (string)$mediaFile->getId();

        $this->apiDelete("/api/v1/movies/{$movieId}", [
            'file_ids' => [$mediaFileId],
            'delete_radarr_reference' => false,
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [200, 202], "Expected 200 or 202, got $statusCode");

        $response = $this->getResponseData();
        $this->assertArrayHasKey('data', $response);
        $this->assertArrayHasKey('deletion_id', $response['data']);
    }
}
