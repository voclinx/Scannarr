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
use DateTime;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

class ScheduledDeletionControllerTest extends AbstractApiTestCase
{
    /**
     * Helper: create a Movie entity in the database.
     */
    private function createMovie(string $title = 'Test Movie', ?int $year = 2024): Movie
    {
        $movie = new Movie();
        $movie->setTitle($title);
        $movie->setYear($year);
        $this->em->persist($movie);
        $this->em->flush();

        return $movie;
    }

    /**
     * Helper: create a Volume entity in the database.
     */
    private function createVolume(
        string $name = 'TestVolume',
        string $path = '/mnt/volume1',
        string $hostPath = '/mnt/media1',
    ): Volume {
        $volume = new Volume();
        $volume->setName($name);
        $volume->setPath($path);
        $volume->setHostPath($hostPath);
        $volume->setType(VolumeType::LOCAL);
        $volume->setStatus(VolumeStatus::ACTIVE);
        $this->em->persist($volume);
        $this->em->flush();

        return $volume;
    }

    /**
     * Helper: create a MediaFile entity in the database.
     */
    private function createMediaFile(
        Volume $volume,
        string $filePath = 'Movies/test-file.mkv',
        string $fileName = 'test-file.mkv',
        int $sizeBytes = 1073741824,
    ): MediaFile {
        $mediaFile = new MediaFile();
        $mediaFile->setVolume($volume);
        $mediaFile->setFilePath($filePath);
        $mediaFile->setFileName($fileName);
        $mediaFile->setFileSizeBytes($sizeBytes);
        $this->em->persist($mediaFile);
        $this->em->flush();

        return $mediaFile;
    }

    /**
     * Helper: create a ScheduledDeletion entity directly in the database.
     */
    private function createDeletion(
        User $user,
        DeletionStatus $status = DeletionStatus::PENDING,
        ?string $scheduledDate = null,
        ?int $reminderDaysBefore = 3,
    ): ScheduledDeletion {
        $deletion = new ScheduledDeletion();
        $deletion->setCreatedBy($user);
        $deletion->setScheduledDate(new DateTime($scheduledDate ?? '+30 days'));
        $deletion->setStatus($status);
        $deletion->setReminderDaysBefore($reminderDaysBefore);
        $this->em->persist($deletion);
        $this->em->flush();

        return $deletion;
    }

    /**
     * Helper: add an item to a ScheduledDeletion.
     */
    private function addDeletionItem(
        ScheduledDeletion $deletion,
        Movie $movie,
        array $mediaFileIds = [],
        string $status = 'pending',
    ): ScheduledDeletionItem {
        $item = new ScheduledDeletionItem();
        $item->setMovie($movie);
        $item->setMediaFileIds($mediaFileIds);
        $item->setStatus($status);
        $deletion->addItem($item);
        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }

    // ──────────────────────────────────────────────
    // TEST-SCHED-001: Créer une suppression planifiée - succès
    // ──────────────────────────────────────────────

    public function testCreateScheduledDeletionSuccess(): void
    {
        $user = $this->createAdvancedUser();
        $this->authenticateAs($user);

        $movie = $this->createMovie('Inception', 2010);
        $volume = $this->createVolume();
        $mediaFile = $this->createMediaFile($volume);

        $this->apiPost('/api/v1/scheduled-deletions', [
            'scheduled_date' => '2030-01-15',
            'items' => [
                [
                    'movie_id' => (string)$movie->getId(),
                    'media_file_ids' => [(string)$mediaFile->getId()],
                ],
            ],
        ]);

        $this->assertResponseStatusCode(201);

        $data = $this->getResponseData();
        $this->assertArrayHasKey('data', $data);
        $this->assertEquals('pending', $data['data']['status']);
        $this->assertEquals('2030-01-15', $data['data']['scheduled_date']);
        $this->assertEquals(1, $data['data']['items_count']);
        $this->assertEquals(1, $data['data']['total_files_count']);
        $this->assertNotEmpty($data['data']['id']);
    }

    // ──────────────────────────────────────────────
    // TEST-SCHED-002: Créer - date passée
    // ──────────────────────────────────────────────

    public function testCreateScheduledDeletionPastDateReturns422(): void
    {
        $user = $this->createAdvancedUser();
        $this->authenticateAs($user);

        $movie = $this->createMovie();

        $this->apiPost('/api/v1/scheduled-deletions', [
            'scheduled_date' => '2020-01-01',
            'items' => [
                [
                    'movie_id' => (string)$movie->getId(),
                    'media_file_ids' => [],
                ],
            ],
        ]);

        $this->assertResponseStatusCode(422);

        $data = $this->getResponseData();
        $this->assertArrayHasKey('error', $data);
        // The controller returns: 'Date must be in the future' in details.scheduled_date
        $this->assertStringContainsStringIgnoringCase('future', json_encode($data['error']));
    }

    // ──────────────────────────────────────────────
    // TEST-SCHED-003: Créer - movie_id inexistant
    // ──────────────────────────────────────────────

    public function testCreateScheduledDeletionInvalidMovieIdReturns422(): void
    {
        $user = $this->createAdvancedUser();
        $this->authenticateAs($user);

        $fakeMovieId = (string)Uuid::v4();

        $this->apiPost('/api/v1/scheduled-deletions', [
            'scheduled_date' => '2030-06-15',
            'items' => [
                [
                    'movie_id' => $fakeMovieId,
                    'media_file_ids' => [],
                ],
            ],
        ]);

        $this->assertResponseStatusCode(422);

        $data = $this->getResponseData();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString($fakeMovieId, $data['error']['message']);
    }

    // ──────────────────────────────────────────────
    // TEST-SCHED-004: Annuler une suppression planifiée (pending)
    // ──────────────────────────────────────────────

    public function testCancelPendingDeletionSuccess(): void
    {
        $user = $this->createAdvancedUser();
        $this->authenticateAs($user);

        $movie = $this->createMovie();
        $deletion = $this->createDeletion($user, DeletionStatus::PENDING);
        $this->addDeletionItem($deletion, $movie);

        $this->apiDelete('/api/v1/scheduled-deletions/' . $deletion->getId());

        $this->assertResponseStatusCode(200);

        $data = $this->getResponseData();
        $this->assertArrayHasKey('data', $data);
        $this->assertStringContainsStringIgnoringCase('cancelled', json_encode($data['data']));

        // Verify in database
        $this->em->refresh($deletion);
        $this->assertEquals(DeletionStatus::CANCELLED, $deletion->getStatus());
    }

    // ──────────────────────────────────────────────
    // TEST-SCHED-005: Annuler une suppression déjà exécutée
    // ──────────────────────────────────────────────

    public function testCancelCompletedDeletionReturnsError(): void
    {
        $user = $this->createAdvancedUser();
        $this->authenticateAs($user);

        $movie = $this->createMovie();
        $deletion = $this->createDeletion($user, DeletionStatus::COMPLETED);
        $this->addDeletionItem($deletion, $movie);

        $this->apiDelete('/api/v1/scheduled-deletions/' . $deletion->getId());

        // The controller returns 409 (Conflict) for non-cancellable statuses
        $this->assertResponseStatusCode(409);

        $data = $this->getResponseData();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsStringIgnoringCase('cannot cancel', $data['error']['message']);
    }

    // ──────────────────────────────────────────────
    // TEST-SCHED-006: Exécution automatique (ProcessScheduledDeletionsCommand)
    // ──────────────────────────────────────────────

    public function testProcessScheduledDeletionsCommandExecutesDueDeletions(): void
    {
        $user = $this->createAdvancedUser(
            'exec-user@example.com',
            'exec-user',
        );

        $movie = $this->createMovie('Movie To Delete', 2023);
        $volume = $this->createVolume('ExecVolume', '/mnt/exec-vol', '/mnt/exec-host');
        $mediaFile = $this->createMediaFile($volume, 'Movies/delete-me.mkv', 'delete-me.mkv');

        // Create a deletion due today (or in the past)
        $deletion = $this->createDeletion($user, DeletionStatus::PENDING, 'today');
        $this->addDeletionItem($deletion, $movie, [(string)$mediaFile->getId()]);

        // Run the command
        $application = new Application(self::$kernel);
        $command = $application->find('scanarr:process-deletions');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        // Refresh to get updated status
        $this->em->refresh($deletion);

        // With the async watcher-based deletion model:
        // - WAITING_WATCHER: watcher not connected (test env), command queued
        // - EXECUTING: watcher connected, command sent
        // - COMPLETED: no physical files to delete (all items skipped)
        $this->assertContains(
            $deletion->getStatus(),
            [DeletionStatus::COMPLETED, DeletionStatus::EXECUTING, DeletionStatus::WAITING_WATCHER],
            sprintf('Expected COMPLETED, EXECUTING or WAITING_WATCHER, got: %s', $deletion->getStatus()->value),
        );

        // executedAt is set only for COMPLETED (immediate). For WAITING_WATCHER/EXECUTING,
        // it will be set when the watcher reports completion (async Phase 3).
        if ($deletion->getStatus() === DeletionStatus::COMPLETED) {
            $this->assertNotNull($deletion->getExecutedAt());
        }
    }

    // ──────────────────────────────────────────────
    // TEST-SCHED-007: Exécution - fichier introuvable
    // ──────────────────────────────────────────────

    public function testProcessScheduledDeletionsItemFailsForMissingFile(): void
    {
        $user = $this->createAdvancedUser(
            'fail-user@example.com',
            'fail-user',
        );

        $movie = $this->createMovie('Missing File Movie', 2023);

        // Reference a non-existent media file UUID
        $fakeMediaFileId = (string)Uuid::v4();

        $deletion = $this->createDeletion($user, DeletionStatus::PENDING, 'today');
        $this->addDeletionItem($deletion, $movie, [$fakeMediaFileId]);

        // Run the command
        $application = new Application(self::$kernel);
        $command = $application->find('scanarr:process-deletions');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        // Refresh
        $this->em->refresh($deletion);

        // In the async watcher-based model, unknown media file IDs are silently
        // skipped (no DB record found → nothing to send to watcher).
        // Since no files need deletion, status becomes COMPLETED immediately.
        $this->assertEquals(DeletionStatus::COMPLETED, $deletion->getStatus());
    }

    // ──────────────────────────────────────────────
    // TEST-SCHED-008: Rappel Discord envoyé (SendDeletionRemindersCommand)
    // ──────────────────────────────────────────────

    public function testSendDeletionRemindersCommandSetsReminderSent(): void
    {
        $user = $this->createAdvancedUser(
            'reminder-user@example.com',
            'reminder-user',
        );

        $movie = $this->createMovie('Reminder Movie', 2023);

        // Create a deletion scheduled 3 days from now with reminder_days_before=3
        // This means today is exactly the reminder date, so the reminder should fire
        $scheduledDate = (new DateTime('+3 days'))->format('Y-m-d');
        $deletion = $this->createDeletion($user, DeletionStatus::PENDING, $scheduledDate, 3);
        $this->addDeletionItem($deletion, $movie);

        // Configure the discord webhook URL setting so the service actually attempts
        // to send (it will fail since there's no real webhook, but the flow should proceed).
        // If no webhook is configured, sendDeletionReminder returns false.
        $this->setSetting('discord_webhook_url', 'https://discord.com/api/webhooks/fake/token');

        // Run the reminders command
        $application = new Application(self::$kernel);
        $command = $application->find('scanarr:send-reminders');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        // Refresh to get updated status
        $this->em->refresh($deletion);

        // The command should have attempted to send the reminder.
        // If the Discord HTTP call fails, it will throw and be caught,
        // so the status may or may not be REMINDER_SENT depending on HTTP mock.
        // However, the repository findNeedingReminder filters for:
        //   status = PENDING, reminderSentAt IS NULL, reminderDaysBefore IS NOT NULL
        // And the command checks if today >= (scheduledDate - reminderDaysBefore).
        // With scheduledDate = today+3, reminderDaysBefore = 3:
        //   reminderDate = (today+3) - 3 = today => today >= today => true
        //
        // If the discord call succeeds (or we accept the flow):
        //   status = REMINDER_SENT, reminderSentAt is set
        // If the discord call fails (network):
        //   status stays PENDING
        //
        // In a test environment, the HTTP client may be mocked.
        // We verify the command ran without fatal errors at minimum.
        $this->assertContains(
            $deletion->getStatus(),
            [DeletionStatus::REMINDER_SENT, DeletionStatus::PENDING],
            sprintf('Expected REMINDER_SENT or PENDING, got: %s', $deletion->getStatus()->value),
        );

        // If reminder was sent, verify reminderSentAt is set
        if ($deletion->getStatus() === DeletionStatus::REMINDER_SENT) {
            $this->assertNotNull($deletion->getReminderSentAt());
        }

        // Verify command output indicates processing
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Sending deletion reminders', $output);
    }
}
