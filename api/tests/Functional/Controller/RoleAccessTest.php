<?php

namespace App\Tests\Functional\Controller;

use App\Entity\MediaFile;
use App\Entity\Movie;
use App\Entity\ScheduledDeletion;
use App\Entity\ScheduledDeletionItem;
use App\Entity\User;
use App\Entity\Volume;
use App\Enum\DeletionStatus;
use App\Enum\VolumeStatus;
use App\Enum\VolumeType;
use App\Tests\AbstractApiTestCase;

/**
 * Functional tests for role-based access control.
 *
 * TEST-ROLE-001: Admin can access settings
 * TEST-ROLE-002: Standard user cannot access settings
 * TEST-ROLE-003: Guest cannot delete a file
 * TEST-ROLE-004: Advanced user can delete a file
 * TEST-ROLE-005: Advanced user can only cancel their own scheduled deletions
 * TEST-ROLE-006: Admin can cancel any scheduled deletion
 */
class RoleAccessTest extends AbstractApiTestCase
{
    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function createVolume(string $name = 'Test Volume'): Volume
    {
        $volume = new Volume();
        $volume->setName($name);
        $volume->setPath('/mnt/test-' . uniqid());
        $volume->setHostPath('/host/test-' . uniqid());
        $volume->setType(VolumeType::LOCAL);
        $volume->setStatus(VolumeStatus::ACTIVE);

        $this->em->persist($volume);
        $this->em->flush();

        return $volume;
    }

    private function createMediaFile(Volume $volume, string $fileName = 'test-movie.mkv'): MediaFile
    {
        $file = new MediaFile();
        $file->setVolume($volume);
        $file->setFilePath('movies/' . $fileName);
        $file->setFileName($fileName);
        $file->setFileSizeBytes(1_500_000_000);

        $this->em->persist($file);
        $this->em->flush();

        return $file;
    }

    private function createMovie(string $title = 'Test Movie'): Movie
    {
        $movie = new Movie();
        $movie->setTitle($title);

        $this->em->persist($movie);
        $this->em->flush();

        return $movie;
    }

    private function createScheduledDeletion(User $owner): ScheduledDeletion
    {
        $movie = $this->createMovie();

        $deletion = new ScheduledDeletion();
        $deletion->setCreatedBy($owner);
        $deletion->setScheduledDate(new \DateTime('+30 days'));
        $deletion->setStatus(DeletionStatus::PENDING);

        $item = new ScheduledDeletionItem();
        $item->setMovie($movie);
        $item->setMediaFileIds([]);
        $deletion->addItem($item);

        $this->em->persist($deletion);
        $this->em->flush();

        return $deletion;
    }

    // ---------------------------------------------------------------
    // TEST-ROLE-001: Admin accesses settings -> 200
    // ---------------------------------------------------------------

    public function testAdminCanAccessSettings(): void
    {
        $admin = $this->createAdmin();
        $this->authenticateAs($admin);

        $this->apiGet('/api/v1/settings');

        $this->assertResponseStatusCode(200);
    }

    // ---------------------------------------------------------------
    // TEST-ROLE-002: Standard user cannot access settings -> 403
    // ---------------------------------------------------------------

    public function testStandardUserCannotAccessSettings(): void
    {
        $user = $this->createUser();
        $this->authenticateAs($user);

        $this->apiGet('/api/v1/settings');

        $this->assertResponseStatusCode(403);
    }

    // ---------------------------------------------------------------
    // TEST-ROLE-003: Guest cannot delete a file -> 403
    // ---------------------------------------------------------------

    public function testGuestCannotDeleteFile(): void
    {
        $guest = $this->createGuest();
        $volume = $this->createVolume();
        $file = $this->createMediaFile($volume);

        $this->authenticateAs($guest);

        $this->apiDelete('/api/v1/files/' . $file->getId());

        $this->assertResponseStatusCode(403);
    }

    // ---------------------------------------------------------------
    // TEST-ROLE-004: Advanced user can delete a file -> 200
    // ---------------------------------------------------------------

    public function testAdvancedUserCanDeleteFile(): void
    {
        $advancedUser = $this->createAdvancedUser();
        $volume = $this->createVolume();
        $file = $this->createMediaFile($volume);

        $this->authenticateAs($advancedUser);

        $this->apiDelete('/api/v1/files/' . $file->getId(), [
            'delete_physical' => false,
            'delete_radarr_reference' => false,
        ]);

        $this->assertResponseStatusCode(200);

        $responseData = $this->getResponseData();
        $this->assertEquals('File deleted successfully', $responseData['data']['message']);
    }

    // ---------------------------------------------------------------
    // TEST-ROLE-005: Advanced user cannot cancel another user's
    //               scheduled deletion -> 403
    // ---------------------------------------------------------------

    public function testAdvancedUserCannotCancelOthersDeletion(): void
    {
        // Create the deletion owned by a different advanced user
        $owner = $this->createAdvancedUser(
            email: 'owner@example.com',
            username: 'owner',
        );
        $deletion = $this->createScheduledDeletion($owner);

        // Authenticate as a different advanced user
        $otherAdvanced = $this->createAdvancedUser(
            email: 'other-advanced@example.com',
            username: 'other-advanced',
        );
        $this->authenticateAs($otherAdvanced);

        $this->apiDelete('/api/v1/scheduled-deletions/' . $deletion->getId());

        $this->assertResponseStatusCode(403);
    }

    // ---------------------------------------------------------------
    // TEST-ROLE-006: Admin can cancel any scheduled deletion -> 200
    // ---------------------------------------------------------------

    public function testAdminCanCancelAnyDeletion(): void
    {
        // Create the deletion owned by a regular advanced user
        $owner = $this->createAdvancedUser(
            email: 'deletion-owner@example.com',
            username: 'deletion-owner',
        );
        $deletion = $this->createScheduledDeletion($owner);

        // Authenticate as admin (different user)
        $admin = $this->createAdmin();
        $this->authenticateAs($admin);

        $this->apiDelete('/api/v1/scheduled-deletions/' . $deletion->getId());

        $this->assertResponseStatusCode(200);

        $responseData = $this->getResponseData();
        $this->assertEquals('Scheduled deletion cancelled', $responseData['data']['message']);
    }
}
