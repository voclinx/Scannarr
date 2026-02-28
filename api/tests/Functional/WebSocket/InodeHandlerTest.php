<?php

declare(strict_types=1);

namespace App\Tests\Functional\WebSocket;

use App\Entity\MediaFile;
use App\Entity\Volume;
use App\Enum\VolumeStatus;
use App\Enum\VolumeType;
use App\Repository\MediaFileRepository;
use App\Tests\AbstractApiTestCase;
use App\WebSocket\Handler\FileCreatedHandler;
use App\WebSocket\Handler\FileModifiedHandler;
use App\WebSocket\Handler\FileRenamedHandler;

/**
 * Verifies that inode + device_id are stored correctly by WebSocket handlers
 * and that findAllByInode() returns all hardlink siblings.
 */
class InodeHandlerTest extends AbstractApiTestCase
{
    private FileCreatedHandler $fileCreatedHandler;
    private FileRenamedHandler $fileRenamedHandler;
    private FileModifiedHandler $fileModifiedHandler;
    private MediaFileRepository $mediaFileRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $container = static::getContainer();
        $this->fileCreatedHandler = $container->get(FileCreatedHandler::class);
        $this->fileRenamedHandler = $container->get(FileRenamedHandler::class);
        $this->fileModifiedHandler = $container->get(FileModifiedHandler::class);
        $this->mediaFileRepository = $container->get(MediaFileRepository::class);
    }

    // ─────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────

    private function createVolume(string $name = 'TestVol', string $hostPath = '/mnt/media'): Volume
    {
        $volume = new Volume();
        $volume->setName($name);
        $volume->setPath('/api/' . strtolower(str_replace(' ', '-', $name)));
        $volume->setHostPath($hostPath);
        $volume->setStatus(VolumeStatus::ACTIVE);
        $volume->setType(VolumeType::LOCAL);
        $this->em->persist($volume);
        $this->em->flush();

        return $volume;
    }

    // ─────────────────────────────────────────────────
    // FileCreatedHandler: inode stored on new file
    // ─────────────────────────────────────────────────

    public function testFileCreatedStoresInode(): void
    {
        $this->createVolume('Vol1', '/mnt/media');

        $this->fileCreatedHandler->handleCreated([
            'path'           => '/mnt/media/Movies/film.mkv',
            'name'           => 'film.mkv',
            'size_bytes'     => 1000000,
            'hardlink_count' => 1,
            'inode'          => 123456,
            'device_id'      => 65024,
        ]);

        $file = $this->mediaFileRepository->findByInode('65024', '123456');
        $this->assertNotNull($file, 'findByInode() should find the created file');
        $this->assertSame('123456', $file->getInode());
        $this->assertSame('65024', $file->getDeviceId());
    }

    // ─────────────────────────────────────────────────
    // FileCreatedHandler: inode updated on re-create
    // ─────────────────────────────────────────────────

    public function testFileCreatedUpdatesInodeOnReCreate(): void
    {
        $volume = $this->createVolume('Vol2', '/mnt/nas');

        // Pre-create file without inode
        $mediaFile = new MediaFile();
        $mediaFile->setVolume($volume);
        $mediaFile->setFilePath('Movies/movie.mkv');
        $mediaFile->setFileName('movie.mkv');
        $mediaFile->setFileSizeBytes(500000);
        $this->em->persist($mediaFile);
        $this->em->flush();

        $this->assertNull($mediaFile->getInode());

        // Re-created event with inode
        $this->fileCreatedHandler->handleCreated([
            'path'           => '/mnt/nas/Movies/movie.mkv',
            'name'           => 'movie.mkv',
            'size_bytes'     => 500000,
            'hardlink_count' => 2,
            'inode'          => 999888,
            'device_id'      => 65024,
        ]);

        $this->em->refresh($mediaFile);
        $this->assertSame('999888', $mediaFile->getInode());
        $this->assertSame('65024', $mediaFile->getDeviceId());
    }

    // ─────────────────────────────────────────────────
    // FileRenamedHandler: inode re-confirmed after rename
    // ─────────────────────────────────────────────────

    public function testFileRenamedPreservesInode(): void
    {
        $volume = $this->createVolume('Vol3', '/mnt/data');

        $mediaFile = new MediaFile();
        $mediaFile->setVolume($volume);
        $mediaFile->setFilePath('old/film.mkv');
        $mediaFile->setFileName('film.mkv');
        $mediaFile->setFileSizeBytes(800000);
        $this->em->persist($mediaFile);
        $this->em->flush();

        $this->fileRenamedHandler->handle([
            'type' => 'file.renamed',
            'data' => [
                'old_path'       => '/mnt/data/old/film.mkv',
                'new_path'       => '/mnt/data/new/film.mkv',
                'name'           => 'film.mkv',
                'size_bytes'     => 800000,
                'hardlink_count' => 1,
                'inode'          => 77777,
                'device_id'      => 65024,
            ],
        ]);

        $this->em->refresh($mediaFile);
        $this->assertSame('new/film.mkv', $mediaFile->getFilePath());
        $this->assertSame('77777', $mediaFile->getInode());
    }

    // ─────────────────────────────────────────────────
    // FileModifiedHandler: inode updated on modify
    // ─────────────────────────────────────────────────

    public function testFileModifiedUpdatesInode(): void
    {
        $volume = $this->createVolume('Vol4', '/mnt/store');

        $mediaFile = new MediaFile();
        $mediaFile->setVolume($volume);
        $mediaFile->setFilePath('Films/a.mkv');
        $mediaFile->setFileName('a.mkv');
        $mediaFile->setFileSizeBytes(100000);
        $this->em->persist($mediaFile);
        $this->em->flush();

        $this->fileModifiedHandler->handle([
            'type' => 'file.modified',
            'data' => [
                'path'           => '/mnt/store/Films/a.mkv',
                'name'           => 'a.mkv',
                'size_bytes'     => 100001,
                'hardlink_count' => 1,
                'inode'          => 444111,
                'device_id'      => 65024,
            ],
        ]);

        $this->em->refresh($mediaFile);
        $this->assertSame('444111', $mediaFile->getInode());
        $this->assertSame('65024', $mediaFile->getDeviceId());
    }

    // ─────────────────────────────────────────────────
    // findAllByInode: returns all hardlink siblings
    // ─────────────────────────────────────────────────

    public function testFindAllByInodeReturnsSiblings(): void
    {
        $v1 = $this->createVolume('VolA', '/mnt/a');
        $v2 = $this->createVolume('VolB', '/mnt/b');

        // Create two MediaFiles sharing the same (device_id, inode) — hardlinks
        foreach ([[$v1, 'f1.mkv', 'media/f1.mkv'], [$v2, 'f1.mkv', 'media/f1.mkv']] as [$vol, $name, $path]) {
            $f = new MediaFile();
            $f->setVolume($vol);
            $f->setFilePath($path);
            $f->setFileName($name);
            $f->setFileSizeBytes(500000);
            $f->setInode('55555');
            $f->setDeviceId('65024');
            $this->em->persist($f);
        }
        $this->em->flush();

        $siblings = $this->mediaFileRepository->findAllByInode('65024', '55555');
        $this->assertCount(2, $siblings);
    }

    // ─────────────────────────────────────────────────
    // findByInode: returns null for absent inode
    // ─────────────────────────────────────────────────

    public function testFindByInodeReturnsNullWhenNotFound(): void
    {
        $result = $this->mediaFileRepository->findByInode('99999', '11111');
        $this->assertNull($result);
    }
}
