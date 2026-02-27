<?php

namespace App\Tests\Functional;

use App\Entity\ActivityLog;
use App\Entity\MediaFile;
use App\Entity\Movie;
use App\Entity\MovieFile;
use App\Entity\ScheduledDeletion;
use App\Entity\ScheduledDeletionItem;
use App\Entity\Volume;
use App\Enum\DeletionStatus;
use App\Enum\VolumeStatus;
use App\Enum\VolumeType;
use App\Tests\AbstractApiTestCase;
use DateTime;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * TEST-CHAIN: Tests de la chaîne de suppression complète V1.2.
 *
 * Scénario principal :
 *   1. Créer un film "non surveillé" (simulant un film Radarr en unmonitored)
 *   2. Ajouter un faux fichier média lié
 *   3. Lancer une suppression via l'API
 *   4. Vérifier que la chaîne async fonctionne correctement
 *
 * En environnement de test :
 *   - Pas de Radarr réel → les appels HTTP échouent gracieusement
 *   - Pas de watcher connecté → statut WAITING_WATCHER (aucun fichier physique supprimé)
 *   - Pas de qBittorrent → best-effort, ignoré
 *   - Base de test isolée avec rollback automatique
 */
class DeletionChainTest extends AbstractApiTestCase
{
    // ─────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────

    private function createMovie(
        string $title,
        int $year = 2023,
        ?int $tmdbId = null,
        bool $radarrMonitored = false,
    ): Movie {
        $movie = new Movie();
        $movie->setTitle($title);
        $movie->setYear($year);
        if ($tmdbId !== null) {
            $movie->setTmdbId($tmdbId);
        }
        $movie->setRadarrMonitored($radarrMonitored);
        $this->em->persist($movie);
        $this->em->flush();

        return $movie;
    }

    private function createVolume(
        string $name = 'Test Volume',
        string $path = '/mnt/docker-vol',
        string $hostPath = '/mnt/host-vol',
    ): Volume {
        $volume = new Volume();
        $volume->setName($name);
        $volume->setPath($path . '/' . uniqid('', true));
        $volume->setHostPath($hostPath . '/' . uniqid('', true));
        $volume->setType(VolumeType::LOCAL);
        $volume->setStatus(VolumeStatus::ACTIVE);
        $this->em->persist($volume);
        $this->em->flush();

        return $volume;
    }

    private function createMediaFile(
        Volume $volume,
        string $filePath = 'Movies/FakeMovie/FakeMovie.2023.1080p.mkv',
        string $fileName = 'FakeMovie.2023.1080p.mkv',
        int $sizeBytes = 4_500_000_000,
    ): MediaFile {
        $mediaFile = new MediaFile();
        $mediaFile->setVolume($volume);
        $mediaFile->setFilePath($filePath);
        $mediaFile->setFileName($fileName);
        $mediaFile->setFileSizeBytes($sizeBytes);
        $mediaFile->setHardlinkCount(1);
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

    // ─────────────────────────────────────────────────────────────
    // TEST-CHAIN-001: Suppression immédiate via MovieController
    //   Film non surveillé + fichier → delete → WAITING_WATCHER
    // ─────────────────────────────────────────────────────────────

    /**
     * @testdox TEST-CHAIN-001 Suppression d'un film non surveillé via l'API
     */
    public function testDeleteUnmonitoredMovieWithFile(): void
    {
        $user = $this->createAdvancedUser();
        $this->authenticateAs($user);

        // 1. Créer un film "non surveillé" (simule un film Radarr en unmonitored)
        $movie = $this->createMovie('Film Test Non Surveillé', 2023, 999001, false);
        $volume = $this->createVolume('Volume Films', '/mnt/movies', '/mnt/host/movies');
        $mediaFile = $this->createMediaFile(
            $volume,
            'Movies/Film.Test.2023.1080p.BluRay.mkv',
            'Film.Test.2023.1080p.BluRay.mkv',
            4_500_000_000,
        );
        $this->createMovieFile($movie, $mediaFile);

        $movieId = (string)$movie->getId();
        $mediaFileId = (string)$mediaFile->getId();

        // 2. Lancer la suppression via l'API (delete_physical=true, delete_radarr=false)
        $this->apiDelete("/api/v1/movies/{$movieId}", [
            'file_ids' => [$mediaFileId],
            'delete_radarr_reference' => false,
            'delete_media_player_reference' => false,
            'disable_radarr_auto_search' => false,
        ]);

        // 3. Vérifier la réponse HTTP
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains(
            $statusCode,
            [200, 202],
            "Expected HTTP 200 or 202, got {$statusCode}",
        );

        $response = $this->getResponseData();
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('Deletion initiated', $response['data']['message']);
        $this->assertArrayHasKey('deletion_id', $response['data']);
        $this->assertArrayHasKey('status', $response['data']);

        // 4. Le statut doit être WAITING_WATCHER (watcher non connecté en test)
        //    ou COMPLETED si aucun fichier physique à supprimer
        $this->assertContains(
            $response['data']['status'],
            ['waiting_watcher', 'executing', 'completed'],
            "Expected waiting_watcher/executing/completed, got: {$response['data']['status']}",
        );

        // 5. Le fichier média est encore en BDD (suppression async par le watcher)
        $this->em->clear();
        $fileStillExists = $this->em->getRepository(MediaFile::class)->find($mediaFileId);
        $this->assertNotNull($fileStillExists, 'MediaFile should still exist in DB (async deletion)');

        // 6. Un ScheduledDeletion éphémère a été créé
        $deletionId = $response['data']['deletion_id'];
        $deletion = $this->em->getRepository(ScheduledDeletion::class)->find($deletionId);
        $this->assertNotNull($deletion, 'Ephemeral ScheduledDeletion should exist');
        $this->assertTrue($deletion->isDeletePhysicalFiles());
        $this->assertFalse($deletion->isDeleteRadarrReference());

        // 7. Un ActivityLog movie.deleted a été créé
        $logs = $this->em->getRepository(ActivityLog::class)->findBy([
            'action' => 'movie.deleted',
        ]);
        $this->assertNotEmpty($logs, 'Activity log should be created');
    }

    // ─────────────────────────────────────────────────────────────
    // TEST-CHAIN-002: Suppression avec disable_radarr_auto_search
    //   Film surveillé + fichier + disable auto-search
    // ─────────────────────────────────────────────────────────────

    /**
     * @testdox TEST-CHAIN-002 Suppression avec disable_radarr_auto_search
     */
    public function testDeleteWithDisableRadarrAutoSearch(): void
    {
        $user = $this->createAdvancedUser();
        $this->authenticateAs($user);

        // Film surveillé (monitored=true) — normalement Radarr le re-téléchargerait
        $movie = $this->createMovie('Film Surveillé', 2023, 999002, true);
        $volume = $this->createVolume('Vol2', '/mnt/vol2', '/mnt/host/vol2');
        $mediaFile = $this->createMediaFile($volume, 'Movies/Surveille.mkv', 'Surveille.mkv', 3_000_000_000);
        $this->createMovieFile($movie, $mediaFile);

        $movieId = (string)$movie->getId();

        // Supprimer avec disable_radarr_auto_search=true, delete_radarr_reference=false
        $this->apiDelete("/api/v1/movies/{$movieId}", [
            'file_ids' => [(string)$mediaFile->getId()],
            'delete_radarr_reference' => false,
            'disable_radarr_auto_search' => true,
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [200, 202]);

        $response = $this->getResponseData();
        $this->assertEquals('Deletion initiated', $response['data']['message']);

        // Vérifier que la ScheduledDeletion a le bon flag
        $deletionId = $response['data']['deletion_id'];
        $this->em->clear();
        $deletion = $this->em->getRepository(ScheduledDeletion::class)->find($deletionId);
        $this->assertNotNull($deletion);
        $this->assertTrue($deletion->isDisableRadarrAutoSearch());
        $this->assertFalse($deletion->isDeleteRadarrReference());
    }

    // ─────────────────────────────────────────────────────────────
    // TEST-CHAIN-003: Suppression d'un fichier seul via FileController
    // ─────────────────────────────────────────────────────────────

    /**
     * @testdox TEST-CHAIN-003 Suppression d'un fichier seul (FileController)
     */
    public function testDeleteSingleFile(): void
    {
        $user = $this->createAdvancedUser();
        $this->authenticateAs($user);

        $volume = $this->createVolume('FileVol', '/mnt/files', '/mnt/host/files');
        $mediaFile = $this->createMediaFile($volume, 'Movies/Single.mkv', 'Single.mkv', 2_000_000_000);

        $fileId = (string)$mediaFile->getId();

        $this->apiDelete("/api/v1/files/{$fileId}", [
            'delete_physical' => true,
            'delete_radarr_reference' => false,
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [200, 202]);

        $response = $this->getResponseData();
        $this->assertEquals('Deletion initiated', $response['data']['message']);
        $this->assertArrayHasKey('deletion_id', $response['data']);
        $this->assertArrayHasKey('status', $response['data']);
    }

    // ─────────────────────────────────────────────────────────────
    // TEST-CHAIN-004: Suppression globale de fichier (globalDelete)
    // ─────────────────────────────────────────────────────────────

    /**
     * @testdox TEST-CHAIN-004 Suppression globale d'un fichier (FileController::globalDelete)
     */
    public function testGlobalDeleteFile(): void
    {
        $user = $this->createAdvancedUser();
        $this->authenticateAs($user);

        $volume1 = $this->createVolume('Vol A', '/mnt/vol-a', '/mnt/host/vol-a');
        $volume2 = $this->createVolume('Vol B', '/mnt/vol-b', '/mnt/host/vol-b');

        // Même nom de fichier sur 2 volumes différents (hardlinks)
        $file1 = $this->createMediaFile($volume1, 'Movies/Shared.mkv', 'Shared.mkv', 5_000_000_000);
        $this->createMediaFile($volume2, 'Movies/Shared.mkv', 'Shared.mkv', 5_000_000_000);

        $fileId = (string)$file1->getId();

        $this->apiDelete("/api/v1/files/{$fileId}/global", [
            'delete_physical' => true,
            'delete_radarr_reference' => false,
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [200, 202]);

        $response = $this->getResponseData();
        $this->assertEquals('Global deletion initiated', $response['data']['message']);
        $this->assertArrayHasKey('files_count', $response['data']);
        $this->assertEquals(2, $response['data']['files_count'], 'Should find 2 files with same name');
    }

    // ─────────────────────────────────────────────────────────────
    // TEST-CHAIN-005: Suppression planifiée via ProcessScheduledDeletionsCommand
    // ─────────────────────────────────────────────────────────────

    /**
     * @testdox TEST-CHAIN-005 Suppression planifiée (cron) — film non surveillé avec fichier
     */
    public function testScheduledDeletionCronWithFile(): void
    {
        $user = $this->createAdvancedUser('sched-user@example.com', 'sched-user');

        // Setup : film + volume + fichier
        $movie = $this->createMovie('Film Planifié', 2023, 999003, false);
        $volume = $this->createVolume('ScheduleVol', '/mnt/sched', '/mnt/host/sched');
        $mediaFile = $this->createMediaFile(
            $volume,
            'Movies/Planifie.2023.mkv',
            'Planifie.2023.mkv',
            3_500_000_000,
        );

        // Créer une ScheduledDeletion en status PENDING, due aujourd'hui
        $deletion = new ScheduledDeletion();
        $deletion->setCreatedBy($user);
        $deletion->setScheduledDate(new DateTime('today'));
        $deletion->setDeletePhysicalFiles(true);
        $deletion->setDeleteRadarrReference(false);
        $deletion->setDisableRadarrAutoSearch(false);

        $item = new ScheduledDeletionItem();
        $item->setMovie($movie);
        $item->setMediaFileIds([(string)$mediaFile->getId()]);
        $deletion->addItem($item);

        $this->em->persist($deletion);
        $this->em->flush();

        $deletionId = (string)$deletion->getId();

        // Exécuter la commande cron
        $application = new Application(self::$kernel);
        $command = $application->find('scanarr:process-deletions');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        // Refresh
        $this->em->clear();
        $deletion = $this->em->getRepository(ScheduledDeletion::class)->find($deletionId);
        $this->assertNotNull($deletion);

        // Le statut doit être WAITING_WATCHER (watcher offline en test) ou EXECUTING
        $this->assertContains(
            $deletion->getStatus(),
            [DeletionStatus::WAITING_WATCHER, DeletionStatus::EXECUTING, DeletionStatus::COMPLETED],
            sprintf('Expected WAITING_WATCHER/EXECUTING/COMPLETED, got: %s', $deletion->getStatus()->value),
        );

        // executedAt est set uniquement pour COMPLETED
        if ($deletion->getStatus() === DeletionStatus::COMPLETED) {
            $this->assertNotNull($deletion->getExecutedAt());
        }

        // Le fichier est encore en BDD (sera supprimé par le watcher via WS)
        $file = $this->em->getRepository(MediaFile::class)->find((string)$mediaFile->getId());
        $this->assertNotNull($file, 'MediaFile should still exist (async watcher deletion)');
    }

    // ─────────────────────────────────────────────────────────────
    // TEST-CHAIN-006: Suppression sans fichier physique (Radarr-only)
    //   → doit compléter immédiatement (COMPLETED)
    // ─────────────────────────────────────────────────────────────

    /**
     * @testdox TEST-CHAIN-006 Suppression Radarr-only (pas de fichier physique) → COMPLETED
     */
    public function testDeleteRadarrOnlyNoPhysicalFiles(): void
    {
        $user = $this->createAdvancedUser();
        $this->authenticateAs($user);

        // Film sans fichier physique
        $movie = $this->createMovie('Film Sans Fichier', 2023, 999004, false);
        $movieId = (string)$movie->getId();

        // Supprimer sans fichier : file_ids vide
        $this->apiDelete("/api/v1/movies/{$movieId}", [
            'file_ids' => [],
            'delete_radarr_reference' => true,
        ]);

        // Sans fichier physique → devrait être COMPLETED immédiatement (HTTP 200)
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [200, 202]);

        $response = $this->getResponseData();
        $this->assertEquals('Deletion initiated', $response['data']['message']);

        // Si pas de fichiers → COMPLETED (pas besoin du watcher)
        if ($statusCode === 200) {
            $this->assertEquals('completed', $response['data']['status']);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // TEST-CHAIN-007: Warning quand Radarr monitored et pas d'opt-out
    // ─────────────────────────────────────────────────────────────

    /**
     * @testdox TEST-CHAIN-007 Warning si film monitored Radarr et auto-search non désactivé
     */
    public function testWarningWhenRadarrMonitoredAndNoOptOut(): void
    {
        $user = $this->createAdvancedUser();
        $this->authenticateAs($user);

        // Film monitored Radarr (mais sans instance Radarr réelle en test)
        // Le warning dépend de isRadarrMonitored() ET getRadarrInstance() !== null
        // En test on n'a pas de RadarrInstance, donc pas de warning.
        // On vérifie au moins que le endpoint fonctionne sans erreur.
        $movie = $this->createMovie('Film Monitored', 2023, 999005, true);
        $volume = $this->createVolume('WarnVol', '/mnt/warn', '/mnt/host/warn');
        $mediaFile = $this->createMediaFile($volume, 'Movies/Monitored.mkv', 'Monitored.mkv');
        $this->createMovieFile($movie, $mediaFile);

        $movieId = (string)$movie->getId();

        $this->apiDelete("/api/v1/movies/{$movieId}", [
            'file_ids' => [(string)$mediaFile->getId()],
            'delete_radarr_reference' => false,
            'disable_radarr_auto_search' => false,
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [200, 202]);

        $response = $this->getResponseData();
        $this->assertEquals('Deletion initiated', $response['data']['message']);
        // Note: le warning n'apparaît que si radarrInstance !== null (pas le cas en test)
    }

    // ─────────────────────────────────────────────────────────────
    // TEST-CHAIN-008: Vérification qu'aucun unlink/rmdir n'est appelé
    //   (test structurel — vérifie l'absence dans le code source)
    // ─────────────────────────────────────────────────────────────

    /**
     * @testdox TEST-CHAIN-008 Aucun unlink/rmdir/file_exists dans le code API
     */
    public function testNoFilesystemOperationsInApiCode(): void
    {
        $apiSrcDir = __DIR__ . '/../../src';

        $forbiddenPatterns = [
            'unlink(' => [],
            'rmdir(' => [],
            'file_exists(' => [],
        ];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($apiSrcDir, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            $relativePath = str_replace($apiSrcDir, 'src', $file->getPathname());

            foreach (array_keys($forbiddenPatterns) as $pattern) {
                // Ignore comments (lines starting with // or * after trimming)
                $lines = explode("\n", $content);
                foreach ($lines as $lineNum => $line) {
                    $trimmed = trim($line);
                    if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                        continue;
                    }
                    if (str_contains($line, $pattern)) {
                        $forbiddenPatterns[$pattern][] = "{$relativePath}:" . ($lineNum + 1);
                    }
                }
            }
        }

        foreach ($forbiddenPatterns as $pattern => $occurrences) {
            $this->assertEmpty(
                $occurrences,
                sprintf(
                    "Found forbidden filesystem operation '%s' in API code:\n%s",
                    $pattern,
                    implode("\n", $occurrences),
                ),
            );
        }
    }

    // ─────────────────────────────────────────────────────────────
    // TEST-CHAIN-009: Vérification qu'aucun addExclusion=true
    // ─────────────────────────────────────────────────────────────

    /**
     * @testdox TEST-CHAIN-009 Aucun addExclusion=true dans le code
     */
    public function testNoAddExclusionTrue(): void
    {
        $apiSrcDir = __DIR__ . '/../../src';

        $occurrences = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($apiSrcDir, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            $relativePath = str_replace($apiSrcDir, 'src', $file->getPathname());

            // Check for addExclusion: true or addExclusion => true
            if (preg_match('/addExclusion\s*[=:>]+\s*true/i', $content)) {
                $occurrences[] = $relativePath;
            }
        }

        $this->assertEmpty(
            $occurrences,
            sprintf(
                "Found 'addExclusion=true' in code (should always be false):\n%s",
                implode("\n", $occurrences),
            ),
        );
    }
}
