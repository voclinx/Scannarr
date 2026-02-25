<?php

namespace App\Tests\Unit\WebSocket;

use App\Entity\MediaFile;
use App\Entity\Volume;
use App\Repository\MediaFileRepository;
use App\Repository\VolumeRepository;
use App\WebSocket\WatcherMessageProcessor;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

class WatcherMessageProcessorTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private ManagerRegistry&MockObject $managerRegistry;
    private VolumeRepository&MockObject $volumeRepository;
    private MediaFileRepository&MockObject $mediaFileRepository;
    private LoggerInterface&MockObject $logger;
    private WatcherMessageProcessor $processor;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->managerRegistry = $this->createMock(ManagerRegistry::class);
        $this->volumeRepository = $this->createMock(VolumeRepository::class);
        $this->mediaFileRepository = $this->createMock(MediaFileRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // EntityManager is always open
        $this->em->method('isOpen')->willReturn(true);

        $this->processor = new WatcherMessageProcessor(
            $this->em,
            $this->managerRegistry,
            $this->volumeRepository,
            $this->mediaFileRepository,
            $this->logger,
        );
    }

    /**
     * Create a Volume mock with the given host path and name.
     */
    private function createVolumeMock(
        string $hostPath = '/mnt/media1',
        string $name = 'TestVolume',
    ): Volume&MockObject {
        $volume = $this->createMock(Volume::class);
        $volume->method('getHostPath')->willReturn($hostPath);
        $volume->method('getName')->willReturn($name);
        $volume->method('getId')->willReturn(Uuid::v4());

        return $volume;
    }

    /**
     * Create a MediaFile mock.
     */
    private function createMediaFileMock(
        ?string $fileName = 'test.mkv',
        int $sizeBytes = 1073741824,
        int $hardlinkCount = 1,
    ): MediaFile&MockObject {
        $mediaFile = $this->createMock(MediaFile::class);
        $mediaFile->method('getFileName')->willReturn($fileName);
        $mediaFile->method('getFileSizeBytes')->willReturn($sizeBytes);
        $mediaFile->method('getHardlinkCount')->willReturn($hardlinkCount);
        $mediaFile->method('getId')->willReturn(Uuid::v4());

        return $mediaFile;
    }

    // ──────────────────────────────────────────────
    // TEST-WH-001: Réception file.created
    // ──────────────────────────────────────────────

    public function testFileCreatedPersistsNewMediaFile(): void
    {
        $volume = $this->createVolumeMock('/mnt/media1', 'Movies');

        // VolumeRepository returns the volume for the given host path
        $this->volumeRepository
            ->method('findByHostPathPrefix')
            ->with('/mnt/media1/Movies/Inception.2010.mkv')
            ->willReturn($volume);

        // No existing file in DB
        $this->mediaFileRepository
            ->method('findByVolumeAndFilePath')
            ->with($volume, 'Movies/Inception.2010.mkv')
            ->willReturn(null);

        // Expect persist with a new MediaFile entity
        $this->em->expects($this->atLeastOnce())
            ->method('persist')
            ->with($this->callback(function ($entity) {
                return $entity instanceof MediaFile
                    && $entity->getFileName() === 'Inception.2010.mkv'
                    && $entity->getFileSizeBytes() === 5368709120
                    && $entity->getHardlinkCount() === 2;
            }));

        // Expect flush to be called
        $this->em->expects($this->atLeastOnce())
            ->method('flush');

        $this->processor->process([
            'type' => 'file.created',
            'data' => [
                'path' => '/mnt/media1/Movies/Inception.2010.mkv',
                'name' => 'Inception.2010.mkv',
                'size_bytes' => 5368709120,
                'hardlink_count' => 2,
            ],
        ]);
    }

    // ──────────────────────────────────────────────
    // TEST-WH-002: Réception file.deleted
    // ──────────────────────────────────────────────

    public function testFileDeletedRemovesMediaFile(): void
    {
        $volume = $this->createVolumeMock('/mnt/media1', 'Movies');
        $existingFile = $this->createMediaFileMock('OldMovie.mkv', 2147483648);

        $this->volumeRepository
            ->method('findByHostPathPrefix')
            ->with('/mnt/media1/Movies/OldMovie.mkv')
            ->willReturn($volume);

        $this->mediaFileRepository
            ->method('findByVolumeAndFilePath')
            ->with($volume, 'Movies/OldMovie.mkv')
            ->willReturn($existingFile);

        // Expect remove to be called with the existing media file
        $this->em->expects($this->once())
            ->method('remove')
            ->with($existingFile);

        // Expect flush after remove
        $this->em->expects($this->atLeastOnce())
            ->method('flush');

        // Expect persist for the ActivityLog entry
        $this->em->expects($this->atLeastOnce())
            ->method('persist');

        $this->processor->process([
            'type' => 'file.deleted',
            'data' => [
                'path' => '/mnt/media1/Movies/OldMovie.mkv',
                'name' => 'OldMovie.mkv',
            ],
        ]);
    }

    // ──────────────────────────────────────────────
    // TEST-WH-003: Réception file.renamed
    // ──────────────────────────────────────────────

    public function testFileRenamedUpdatesMediaFilePath(): void
    {
        $volume = $this->createVolumeMock('/mnt/media1', 'Movies');

        // Create a real (partial) MediaFile that we can verify updates on
        $mediaFile = new MediaFile();
        $mediaFile->setVolume($volume);
        $mediaFile->setFilePath('Movies/old-name.mkv');
        $mediaFile->setFileName('old-name.mkv');
        $mediaFile->setFileSizeBytes(1073741824);
        $mediaFile->setHardlinkCount(1);

        $this->volumeRepository
            ->method('findByHostPathPrefix')
            ->willReturn($volume);

        $this->mediaFileRepository
            ->method('findByVolumeAndFilePath')
            ->with($volume, 'Movies/old-name.mkv')
            ->willReturn($mediaFile);

        $this->em->expects($this->atLeastOnce())
            ->method('flush');

        $this->processor->process([
            'type' => 'file.renamed',
            'data' => [
                'old_path' => '/mnt/media1/Movies/old-name.mkv',
                'new_path' => '/mnt/media1/Movies/new-name.mkv',
                'name' => 'new-name.mkv',
                'size_bytes' => 1073741824,
                'hardlink_count' => 1,
            ],
        ]);

        // Verify the media file was updated with the new path and name
        $this->assertEquals('Movies/new-name.mkv', $mediaFile->getFilePath());
        $this->assertEquals('new-name.mkv', $mediaFile->getFileName());
    }

    // ──────────────────────────────────────────────
    // TEST-WH-004: Réception scan.file - création
    // ──────────────────────────────────────────────

    public function testScanFileCreatesNewMediaFile(): void
    {
        $volume = $this->createVolumeMock('/mnt/media1', 'Movies');
        $scanId = 'scan-' . Uuid::v4();

        $this->volumeRepository
            ->method('findByHostPathPrefix')
            ->willReturn($volume);

        // No existing file
        $this->mediaFileRepository
            ->method('findByVolumeAndFilePath')
            ->willReturn(null);

        // Expect persist for the new MediaFile
        $this->em->expects($this->atLeastOnce())
            ->method('persist')
            ->with($this->callback(function ($entity) {
                // Accept both MediaFile (the file being created) and ActivityLog
                if ($entity instanceof MediaFile) {
                    return $entity->getFileName() === 'ScanMovie.mkv'
                        && $entity->getFileSizeBytes() === 3221225472;
                }
                return true; // Accept other entities (ActivityLog, etc.)
            }));

        // First, simulate scan.started to register the scan context
        $this->processor->process([
            'type' => 'scan.started',
            'data' => [
                'scan_id' => $scanId,
                'path' => '/mnt/media1',
            ],
        ]);

        // Then process scan.file
        $this->processor->process([
            'type' => 'scan.file',
            'data' => [
                'scan_id' => $scanId,
                'path' => '/mnt/media1/Movies/ScanMovie.mkv',
                'name' => 'ScanMovie.mkv',
                'size_bytes' => 3221225472,
                'hardlink_count' => 1,
            ],
        ]);
    }

    // ──────────────────────────────────────────────
    // TEST-WH-005: Réception scan.file - mise à jour
    // ──────────────────────────────────────────────

    public function testScanFileUpdatesExistingMediaFile(): void
    {
        $volume = $this->createVolumeMock('/mnt/media1', 'Movies');
        $scanId = 'scan-' . Uuid::v4();

        // Create a real MediaFile so we can check its properties are updated
        $existingFile = new MediaFile();
        $existingFile->setVolume($volume);
        $existingFile->setFilePath('Movies/Existing.mkv');
        $existingFile->setFileName('Existing.mkv');
        $existingFile->setFileSizeBytes(1073741824); // 1 GB
        $existingFile->setHardlinkCount(1);

        $this->volumeRepository
            ->method('findByHostPathPrefix')
            ->willReturn($volume);

        $this->mediaFileRepository
            ->method('findByVolumeAndFilePath')
            ->with($volume, 'Movies/Existing.mkv')
            ->willReturn($existingFile);

        // persist should NOT be called for an update (only for new entities)
        // but flush may be called for batch
        // Actually, the code does not call persist for existing files in handleScanFile,
        // so we should not expect persist for a MediaFile here.

        // First, simulate scan.started
        $this->processor->process([
            'type' => 'scan.started',
            'data' => [
                'scan_id' => $scanId,
                'path' => '/mnt/media1',
            ],
        ]);

        // Process scan.file with new size (2 GB) - simulating a modified file
        $this->processor->process([
            'type' => 'scan.file',
            'data' => [
                'scan_id' => $scanId,
                'path' => '/mnt/media1/Movies/Existing.mkv',
                'name' => 'Existing.mkv',
                'size_bytes' => 2147483648, // 2 GB (was 1 GB)
                'hardlink_count' => 3,
            ],
        ]);

        // Verify the file was updated with new size and hardlink count
        $this->assertEquals(2147483648, $existingFile->getFileSizeBytes());
        $this->assertEquals(3, $existingFile->getHardlinkCount());
    }

    // ──────────────────────────────────────────────
    // TEST-WH-006: Réception scan.completed
    // ──────────────────────────────────────────────

    public function testScanCompletedUpdatesVolumeLastScanAt(): void
    {
        // Use a real Volume object so we can verify lastScanAt is updated
        $volume = new Volume();
        $volume->setName('ScanVolume');
        $volume->setPath('/mnt/volume-scan');
        $volume->setHostPath('/mnt/media-scan');
        // lastScanAt starts as null
        $this->assertNull($volume->getLastScanAt());

        $scanId = 'scan-' . Uuid::v4();

        $this->volumeRepository
            ->method('findByHostPathPrefix')
            ->with('/mnt/media-scan')
            ->willReturn($volume);

        // During scan.completed, findAllFilePathsByVolume is called to detect stale files
        $this->mediaFileRepository
            ->method('findAllFilePathsByVolume')
            ->with($volume)
            ->willReturn([]); // No files in DB (clean state)

        // Expect flush to be called (for volume update and final scan flush)
        $this->em->expects($this->atLeastOnce())
            ->method('flush');

        // Expect persist for the ActivityLog
        $this->em->expects($this->atLeastOnce())
            ->method('persist');

        // Expect clear to be called at the end of scan.completed
        $this->em->expects($this->atLeastOnce())
            ->method('clear');

        // Step 1: Start the scan
        $this->processor->process([
            'type' => 'scan.started',
            'data' => [
                'scan_id' => $scanId,
                'path' => '/mnt/media-scan',
            ],
        ]);

        // Step 2: Complete the scan
        $this->processor->process([
            'type' => 'scan.completed',
            'data' => [
                'scan_id' => $scanId,
                'path' => '/mnt/media-scan',
                'total_files' => 42,
                'total_dirs' => 5,
                'total_size_bytes' => 107374182400,
                'duration_ms' => 1500,
            ],
        ]);

        // Verify volume lastScanAt has been updated
        $this->assertNotNull($volume->getLastScanAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $volume->getLastScanAt());

        // Verify usedSpaceBytes was updated from total_size_bytes
        $this->assertEquals(107374182400, $volume->getUsedSpaceBytes());
    }
}
