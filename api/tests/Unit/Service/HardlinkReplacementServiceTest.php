<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\MediaFile;
use App\Entity\Movie;
use App\Entity\MovieFile;
use App\Service\HardlinkReplacementService;
use App\Service\WatcherCommandService;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

class HardlinkReplacementServiceTest extends TestCase
{
    private WatcherCommandService&Stub $watcherCommandService;
    private HardlinkReplacementService $service;

    protected function setUp(): void
    {
        $this->watcherCommandService = $this->createStub(WatcherCommandService::class);
        $this->service = new HardlinkReplacementService(
            $this->watcherCommandService,
            new NullLogger(),
        );
    }

    public function testIsMediaPlayerFileTrue(): void
    {
        $file = new MediaFile();
        $file->setIsLinkedMediaPlayer(true);
        $file->setFileName('file.mkv');
        $file->setFilePath('movies/file.mkv');

        $this->assertTrue($this->service->isMediaPlayerFile($file));
    }

    public function testIsMediaPlayerFileFalse(): void
    {
        $file = new MediaFile();
        $file->setIsLinkedMediaPlayer(false);
        $file->setFileName('file.mkv');
        $file->setFilePath('movies/file.mkv');

        $this->assertFalse($this->service->isMediaPlayerFile($file));
    }

    public function testSuggestReplacementSortsByResolutionThenQuality(): void
    {
        // Candidate A: 1080p web-dl
        $fileA = $this->createStub(MediaFile::class);
        $fileA->method('getId')->willReturn(Uuid::v4());
        $fileA->method('getResolution')->willReturn('1080p');
        $fileA->method('getQuality')->willReturn('web-dl');
        $fileA->method('getFileSizeBytes')->willReturn(4000000000);

        // Candidate B: 720p bluray
        $fileB = $this->createStub(MediaFile::class);
        $fileB->method('getId')->willReturn(Uuid::v4());
        $fileB->method('getResolution')->willReturn('720p');
        $fileB->method('getQuality')->willReturn('bluray');
        $fileB->method('getFileSizeBytes')->willReturn(2000000000);

        // Candidate C: 2160p remux — should be ranked first
        $fileC = $this->createStub(MediaFile::class);
        $fileC->method('getId')->willReturn(Uuid::v4());
        $fileC->method('getResolution')->willReturn('2160p');
        $fileC->method('getQuality')->willReturn('remux');
        $fileC->method('getFileSizeBytes')->willReturn(50000000000);

        $movie = $this->buildMovieWithFiles([$fileA, $fileB, $fileC]);

        $result = $this->service->suggestReplacement($movie, []);

        $this->assertNotNull($result);
        // Best candidate is 2160p remux
        $this->assertSame($fileC, $result['suggested']);
        $this->assertCount(2, $result['alternatives']);
    }

    public function testSuggestReplacementNoCandidate(): void
    {
        $id1 = (string)Uuid::v4();
        $id2 = (string)Uuid::v4();

        $file1 = $this->createStub(MediaFile::class);
        $file1->method('getId')->willReturn(Uuid::fromString($id1));

        $file2 = $this->createStub(MediaFile::class);
        $file2->method('getId')->willReturn(Uuid::fromString($id2));

        $movie = $this->buildMovieWithFiles([$file1, $file2]);

        // Exclude all candidates
        $result = $this->service->suggestReplacement($movie, [$id1, $id2]);

        $this->assertNull($result);
    }

    /**
     * @param MediaFile[] $mediaFiles
     */
    private function buildMovieWithFiles(array $mediaFiles): Movie
    {
        $movieFiles = [];
        foreach ($mediaFiles as $mediaFile) {
            $mf = $this->createStub(MovieFile::class);
            $mf->method('getMediaFile')->willReturn($mediaFile);
            $movieFiles[] = $mf;
        }

        $movie = $this->createStub(Movie::class);
        $movie->method('getMovieFiles')->willReturn(new ArrayCollection($movieFiles));

        return $movie;
    }
}
