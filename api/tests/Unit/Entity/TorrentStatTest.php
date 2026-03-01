<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\MediaFile;
use App\Entity\TorrentStat;
use App\Enum\TorrentStatus;
use DateTimeImmutable;
use Doctrine\Common\Collections\Collection;
use PHPUnit\Framework\TestCase;

class TorrentStatTest extends TestCase
{
    private TorrentStat $stat;

    protected function setUp(): void
    {
        $this->stat = new TorrentStat();
    }

    // ---------------------------------------------------------------
    // Constructor
    // ---------------------------------------------------------------

    public function testConstructorInitializesTimestamps(): void
    {
        $now = new DateTimeImmutable();

        $this->assertInstanceOf(DateTimeImmutable::class, $this->stat->getCreatedAt());
        $this->assertInstanceOf(DateTimeImmutable::class, $this->stat->getUpdatedAt());
        $this->assertInstanceOf(DateTimeImmutable::class, $this->stat->getFirstSeenAt());
        $this->assertInstanceOf(DateTimeImmutable::class, $this->stat->getLastSyncedAt());

        // Timestamps should be approximately now (within 2 seconds)
        $this->assertEqualsWithDelta($now->getTimestamp(), $this->stat->getCreatedAt()->getTimestamp(), 2);
        $this->assertEqualsWithDelta($now->getTimestamp(), $this->stat->getUpdatedAt()->getTimestamp(), 2);
    }

    public function testConstructorInitializesEmptyHistory(): void
    {
        $this->assertInstanceOf(Collection::class, $this->stat->getHistory());
        $this->assertCount(0, $this->stat->getHistory());
    }

    public function testConstructorDefaultStatusIsSeeding(): void
    {
        $this->assertSame(TorrentStatus::SEEDING, $this->stat->getStatus());
    }

    public function testConstructorDefaultValues(): void
    {
        $this->assertSame('0.0000', $this->stat->getRatio());
        $this->assertSame(0, $this->stat->getSeedTimeSeconds());
        $this->assertSame(0, $this->stat->getUploadedBytes());
        $this->assertSame(0, $this->stat->getDownloadedBytes());
        $this->assertSame(0, $this->stat->getSizeBytes());
    }

    // ---------------------------------------------------------------
    // ID
    // ---------------------------------------------------------------

    public function testIdIsNullBeforePersist(): void
    {
        $this->assertNull($this->stat->getId());
    }

    // ---------------------------------------------------------------
    // Getters / Setters
    // ---------------------------------------------------------------

    public function testTorrentHash(): void
    {
        $result = $this->stat->setTorrentHash('abc123def456');
        $this->assertSame('abc123def456', $this->stat->getTorrentHash());
        $this->assertSame($this->stat, $result);
    }

    public function testTorrentName(): void
    {
        $result = $this->stat->setTorrentName('My.Movie.2024.1080p');
        $this->assertSame('My.Movie.2024.1080p', $this->stat->getTorrentName());
        $this->assertSame($this->stat, $result);
    }

    public function testTorrentNameNullable(): void
    {
        $this->stat->setTorrentName('something');
        $this->stat->setTorrentName(null);
        $this->assertNull($this->stat->getTorrentName());
    }

    public function testTrackerDomain(): void
    {
        $result = $this->stat->setTrackerDomain('tracker.example.com');
        $this->assertSame('tracker.example.com', $this->stat->getTrackerDomain());
        $this->assertSame($this->stat, $result);
    }

    public function testTrackerDomainNullable(): void
    {
        $this->stat->setTrackerDomain('tracker.example.com');
        $this->stat->setTrackerDomain(null);
        $this->assertNull($this->stat->getTrackerDomain());
    }

    public function testRatio(): void
    {
        $result = $this->stat->setRatio('2.5000');
        $this->assertSame('2.5000', $this->stat->getRatio());
        $this->assertSame($this->stat, $result);
    }

    public function testSeedTimeSeconds(): void
    {
        $result = $this->stat->setSeedTimeSeconds(86400);
        $this->assertSame(86400, $this->stat->getSeedTimeSeconds());
        $this->assertSame($this->stat, $result);
    }

    public function testUploadedBytes(): void
    {
        $result = $this->stat->setUploadedBytes(1073741824);
        $this->assertSame(1073741824, $this->stat->getUploadedBytes());
        $this->assertSame($this->stat, $result);
    }

    public function testDownloadedBytes(): void
    {
        $result = $this->stat->setDownloadedBytes(536870912);
        $this->assertSame(536870912, $this->stat->getDownloadedBytes());
        $this->assertSame($this->stat, $result);
    }

    public function testSizeBytes(): void
    {
        $result = $this->stat->setSizeBytes(2147483648);
        $this->assertSame(2147483648, $this->stat->getSizeBytes());
        $this->assertSame($this->stat, $result);
    }

    public function testStatus(): void
    {
        $result = $this->stat->setStatus(TorrentStatus::PAUSED);
        $this->assertSame(TorrentStatus::PAUSED, $this->stat->getStatus());
        $this->assertSame($this->stat, $result);
    }

    public function testAllStatusValues(): void
    {
        foreach (TorrentStatus::cases() as $status) {
            $this->stat->setStatus($status);
            $this->assertSame($status, $this->stat->getStatus());
        }
    }

    public function testAddedAt(): void
    {
        $date = new DateTimeImmutable('2024-01-15 10:30:00');
        $result = $this->stat->setAddedAt($date);
        $this->assertSame($date, $this->stat->getAddedAt());
        $this->assertSame($this->stat, $result);
    }

    public function testAddedAtNullable(): void
    {
        $this->stat->setAddedAt(new DateTimeImmutable());
        $this->stat->setAddedAt(null);
        $this->assertNull($this->stat->getAddedAt());
    }

    public function testLastActivityAt(): void
    {
        $date = new DateTimeImmutable('2024-06-01 12:00:00');
        $result = $this->stat->setLastActivityAt($date);
        $this->assertSame($date, $this->stat->getLastActivityAt());
        $this->assertSame($this->stat, $result);
    }

    public function testLastActivityAtNullable(): void
    {
        $this->stat->setLastActivityAt(new DateTimeImmutable());
        $this->stat->setLastActivityAt(null);
        $this->assertNull($this->stat->getLastActivityAt());
    }

    public function testQbitContentPath(): void
    {
        $result = $this->stat->setQbitContentPath('/downloads/movie/file.mkv');
        $this->assertSame('/downloads/movie/file.mkv', $this->stat->getQbitContentPath());
        $this->assertSame($this->stat, $result);
    }

    public function testQbitContentPathNullable(): void
    {
        $this->stat->setQbitContentPath('/some/path');
        $this->stat->setQbitContentPath(null);
        $this->assertNull($this->stat->getQbitContentPath());
    }

    public function testFirstSeenAt(): void
    {
        $date = new DateTimeImmutable('2024-03-01');
        $result = $this->stat->setFirstSeenAt($date);
        $this->assertSame($date, $this->stat->getFirstSeenAt());
        $this->assertSame($this->stat, $result);
    }

    public function testLastSyncedAt(): void
    {
        $date = new DateTimeImmutable('2024-06-15');
        $result = $this->stat->setLastSyncedAt($date);
        $this->assertSame($date, $this->stat->getLastSyncedAt());
        $this->assertSame($this->stat, $result);
    }

    // ---------------------------------------------------------------
    // Relations
    // ---------------------------------------------------------------

    public function testMediaFileRelation(): void
    {
        $mediaFile = $this->createStub(MediaFile::class);
        $result = $this->stat->setMediaFile($mediaFile);

        $this->assertSame($mediaFile, $this->stat->getMediaFile());
        $this->assertSame($this->stat, $result);
    }

    public function testMediaFileNullable(): void
    {
        $mediaFile = $this->createStub(MediaFile::class);
        $this->stat->setMediaFile($mediaFile);
        $this->stat->setMediaFile(null);

        $this->assertNull($this->stat->getMediaFile());
    }

    // ---------------------------------------------------------------
    // Lifecycle callback
    // ---------------------------------------------------------------

    public function testSetUpdatedAtValueLifecycleCallback(): void
    {
        $originalUpdatedAt = $this->stat->getUpdatedAt();

        // Ensure some time difference
        usleep(10_000); // 10ms

        $this->stat->setUpdatedAtValue();

        $this->assertGreaterThanOrEqual(
            $originalUpdatedAt->getTimestamp(),
            $this->stat->getUpdatedAt()->getTimestamp(),
        );
    }

    // ---------------------------------------------------------------
    // Fluent interface
    // ---------------------------------------------------------------

    public function testFluentInterface(): void
    {
        $mediaFile = $this->createStub(MediaFile::class);

        $result = $this->stat
            ->setMediaFile($mediaFile)
            ->setTorrentHash('abc123')
            ->setTorrentName('Test Torrent')
            ->setTrackerDomain('tracker.test.com')
            ->setRatio('1.5000')
            ->setSeedTimeSeconds(3600)
            ->setUploadedBytes(100)
            ->setDownloadedBytes(50)
            ->setSizeBytes(200)
            ->setStatus(TorrentStatus::SEEDING)
            ->setAddedAt(new DateTimeImmutable())
            ->setLastActivityAt(new DateTimeImmutable())
            ->setQbitContentPath('/path')
            ->setFirstSeenAt(new DateTimeImmutable())
            ->setLastSyncedAt(new DateTimeImmutable());

        $this->assertSame($this->stat, $result);
    }
}
