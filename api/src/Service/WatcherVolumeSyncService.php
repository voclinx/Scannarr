<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Volume;
use App\Enum\VolumeStatus;
use App\Repository\VolumeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Syncs Volume entities from a watcher's watch_paths configuration.
 *
 * When a watcher's config is updated, this service ensures that Volume records
 * match the watcher's watch_paths: creating new ones, renaming changed ones,
 * and deactivating orphaned ones.
 */
final readonly class WatcherVolumeSyncService
{
    public function __construct(
        private EntityManagerInterface $em,
        private VolumeRepository $volumeRepository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Synchronise Volume entities from a watch_paths array.
     *
     * Each entry is expected to be an associative array with keys:
     *   - path  (string, required) — the absolute host path being watched
     *   - name  (string, optional) — human-readable label; defaults to basename
     *
     * @param array $watchPaths Array of [{path: string, name?: string}] objects
     */
    public function sync(array $watchPaths): void
    {
        $wanted = $this->buildWantedMap($watchPaths);

        $allVolumes = $this->volumeRepository->findAll();
        $existing = [];
        foreach ($allVolumes as $vol) {
            $existing[(string)$vol->getHostPath()] = $vol;
        }

        foreach ($wanted as $hostPath => $name) {
            $this->createOrUpdateVolume($hostPath, $name, $existing);
        }

        $this->deactivateOrphanedVolumes($wanted, $existing);

        $this->em->flush();
    }

    /**
     * @param array<int, mixed> $watchPaths
     *
     * @return array<string, string>
     */
    private function buildWantedMap(array $watchPaths): array
    {
        $wanted = [];
        foreach ($watchPaths as $wp) {
            if (!is_array($wp)) {
                continue;
            }
            if (empty($wp['path'])) {
                continue;
            }
            $path = $wp['path'];
            $name = trim((string)($wp['name'] ?? ''));
            if ($name === '') {
                $parts = explode('/', rtrim((string)$path, '/'));
                $name = end($parts) ?: $path;
            }
            $wanted[$path] = $name;
        }

        return $wanted;
    }

    /**
     * @param array<string, Volume> $existing
     */
    private function createOrUpdateVolume(string $hostPath, string $name, array $existing): void
    {
        if (isset($existing[$hostPath])) {
            $this->updateExistingVolume($existing[$hostPath], $hostPath, $name);

            return;
        }

        $vol = new Volume();
        $vol->setName($name);
        $vol->setHostPath($hostPath);
        $vol->setPath($hostPath);
        $vol->setStatus(VolumeStatus::ACTIVE);
        $this->em->persist($vol);

        $this->logger->info('WatcherVolumeSync: created volume', ['host_path' => $hostPath, 'name' => $name]);
    }

    private function updateExistingVolume(Volume $vol, string $hostPath, string $name): void
    {
        $changed = false;

        if ($vol->getName() !== $name) {
            $vol->setName($name);
            $changed = true;
        }
        if ($vol->getStatus() !== VolumeStatus::ACTIVE) {
            $vol->setStatus(VolumeStatus::ACTIVE);
            $changed = true;
        }

        if ($changed) {
            $this->logger->info('WatcherVolumeSync: updated volume', ['host_path' => $hostPath, 'name' => $name]);
        }
    }

    /**
     * @param array<string, string> $wanted
     * @param array<string, Volume> $existing
     */
    private function deactivateOrphanedVolumes(array $wanted, array $existing): void
    {
        foreach ($existing as $hostPath => $vol) {
            if (!isset($wanted[$hostPath]) && $vol->getStatus() === VolumeStatus::ACTIVE) {
                $vol->setStatus(VolumeStatus::INACTIVE);
                $this->logger->info('WatcherVolumeSync: deactivated orphaned volume', ['host_path' => $hostPath]);
            }
        }
    }
}
