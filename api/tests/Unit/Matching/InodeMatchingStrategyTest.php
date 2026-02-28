<?php

declare(strict_types=1);

namespace App\Tests\Unit\Matching;

use App\Contract\Matching\MatchResult;
use App\Entity\MediaFile;
use App\Matching\InodeMatchingStrategy;
use App\Repository\MediaFileRepository;
use PHPUnit\Framework\TestCase;

class InodeMatchingStrategyTest extends TestCase
{
    private MediaFileRepository $repo;
    private InodeMatchingStrategy $strategy;

    protected function setUp(): void
    {
        $this->repo = $this->createStub(MediaFileRepository::class);
        $this->strategy = new InodeMatchingStrategy($this->repo);
    }

    public function testPriorityIsHighest(): void
    {
        $this->assertSame(100, InodeMatchingStrategy::getPriority());
    }

    public function testReturnsNullWhenNoInodeInContext(): void
    {
        $result = $this->strategy->match('/some/path', []);
        $this->assertNull($result);
    }

    public function testReturnsNullWhenOnlyInodeProvided(): void
    {
        $result = $this->strategy->match('/some/path', ['inode' => 42]);
        $this->assertNull($result);
    }

    public function testReturnsNullWhenOnlyDeviceIdProvided(): void
    {
        $result = $this->strategy->match('/some/path', ['device_id' => 12345]);
        $this->assertNull($result);
    }

    public function testReturnsNullWhenNoMatchInRepository(): void
    {
        $this->repo->method('findByInode')->willReturn(null);

        $result = $this->strategy->match('/some/path', ['inode' => 42, 'device_id' => 12345]);

        $this->assertNull($result);
    }

    public function testReturnsMatchResultOnSuccess(): void
    {
        $mediaFile = $this->createStub(MediaFile::class);
        $this->repo->method('findByInode')->willReturn($mediaFile);

        $result = $this->strategy->match('/some/path', ['inode' => 42, 'device_id' => 12345]);

        $this->assertInstanceOf(MatchResult::class, $result);
        $this->assertSame($mediaFile, $result->mediaFile);
        $this->assertSame('inode', $result->strategy);
        $this->assertSame(1.0, $result->confidence);
    }

    public function testPassesStringifiedValuesToRepository(): void
    {
        $repo = $this->createMock(MediaFileRepository::class);
        $repo->expects($this->once())
            ->method('findByInode')
            ->with('12345', '42')
            ->willReturn(null);

        $strategy = new InodeMatchingStrategy($repo);
        $strategy->match('/path', ['inode' => 42, 'device_id' => 12345]);
    }

    public function testContextValuesCanBeStrings(): void
    {
        $mediaFile = $this->createStub(MediaFile::class);
        $this->repo->method('findByInode')->willReturn($mediaFile);

        $result = $this->strategy->match('/some/path', ['inode' => '42', 'device_id' => '12345']);

        $this->assertInstanceOf(MatchResult::class, $result);
    }
}
