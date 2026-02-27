<?php

namespace App\Service;

use App\Entity\MediaFile;
use App\Entity\Movie;
use Psr\Log\LoggerInterface;

class HardlinkReplacementService
{
    public function __construct(
        private readonly WatcherCommandService $watcherCommandService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isMediaPlayerFile(MediaFile $file): bool
    {
        return $file->isLinkedMediaPlayer();
    }

    /**
     * Suggest the best replacement file for a movie, excluding given file IDs.
     * Priority: resolution DESC, quality DESC, size ASC.
     *
     * @param string[] $excludeFileIds
     *
     * @return array{suggested: MediaFile, alternatives: MediaFile[]}|null
     */
    public function suggestReplacement(Movie $movie, array $excludeFileIds): ?array
    {
        $candidates = [];
        foreach ($movie->getMovieFiles() as $mf) {
            $mediaFile = $mf->getMediaFile();
            if ($mediaFile === null) {
                continue;
            }
            if (in_array((string)$mediaFile->getId(), $excludeFileIds, true)) {
                continue;
            }
            $candidates[] = $mediaFile;
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, function (MediaFile $a, MediaFile $b): int {
            $resA = $this->resolutionScore($a->getResolution() ?? '');
            $resB = $this->resolutionScore($b->getResolution() ?? '');
            if ($resA !== $resB) {
                return $resB <=> $resA; // DESC
            }

            $qualA = $this->qualityScore($a->getQuality() ?? '');
            $qualB = $this->qualityScore($b->getQuality() ?? '');
            if ($qualA !== $qualB) {
                return $qualB <=> $qualA; // DESC
            }

            return $a->getFileSizeBytes() <=> $b->getFileSizeBytes(); // ASC
        });

        $suggested = array_shift($candidates);

        return ['suggested' => $suggested, 'alternatives' => $candidates];
    }

    /**
     * Build the target path for the new hardlink in the media player directory.
     * Same directory as currentPlayerFile, filename of replacementFile.
     */
    public function buildTargetPath(MediaFile $currentPlayerFile, MediaFile $replacementFile): string
    {
        $volume = $currentPlayerFile->getVolume();
        $hostPath = rtrim($volume?->getHostPath() ?? $volume?->getPath() ?? '', '/');
        $currentFilePath = $currentPlayerFile->getFilePath() ?? '';
        $parentRelDir = dirname($currentFilePath);
        $newFileName = $replacementFile->getFileName() ?? basename($replacementFile->getFilePath() ?? '');

        if ($parentRelDir === '.') {
            return $hostPath . '/' . $newFileName;
        }

        return $hostPath . '/' . $parentRelDir . '/' . $newFileName;
    }

    /**
     * Request the watcher to create a hardlink replacing currentFile with replacementFile.
     *
     * @return bool True if command sent to watcher
     */
    public function requestReplacement(
        string $deletionId,
        MediaFile $currentFile,
        MediaFile $replacementFile,
    ): bool {
        $repVolume = $replacementFile->getVolume();
        $repHostPath = rtrim($repVolume?->getHostPath() ?? $repVolume?->getPath() ?? '', '/');
        $sourcePath = $repHostPath . '/' . ltrim($replacementFile->getFilePath() ?? '', '/');

        $targetPath = $this->buildTargetPath($currentFile, $replacementFile);

        $curVolume = $currentFile->getVolume();
        $volumePath = rtrim($curVolume?->getHostPath() ?? $curVolume?->getPath() ?? '', '/');

        $this->logger->info('Requesting hardlink replacement', [
            'deletion_id' => $deletionId,
            'source' => $sourcePath,
            'target' => $targetPath,
        ]);

        return $this->watcherCommandService->requestHardlink(
            $deletionId,
            $sourcePath,
            $targetPath,
            $volumePath,
        );
    }

    private function resolutionScore(string $res): int
    {
        return match (strtolower($res)) {
            '2160p', '4k' => 4,
            '1080p' => 3,
            '720p' => 2,
            '480p' => 1,
            default => 0,
        };
    }

    private function qualityScore(string $quality): int
    {
        return match (strtolower($quality)) {
            'remux' => 5,
            'bluray', 'blu-ray' => 4,
            'web-dl', 'webdl' => 3,
            'webrip', 'web-rip' => 2,
            'hdtv' => 1,
            default => 0,
        };
    }
}
