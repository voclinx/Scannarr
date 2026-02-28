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
final class WatcherVolumeSyncService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly VolumeRepository $volumeRepository,
        private readonly LoggerInterface $logger,
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
        // Build wanted map: host_path => name
        $wanted = [];
        foreach ($watchPaths as $wp) {
            if (!is_array($wp) || empty($wp['path'])) {
                continue;
            }
            $path = $wp['path'];
            $name = trim((string)($wp['name'] ?? ''));
            if ($name === '') {
                $parts = explode('/', rtrim($path, '/'));
                $name = end($parts) ?: $path;
            }
            $wanted[$path] = $name;
        }

        // Index all existing volumes by host_path
        $allVolumes = $this->volumeRepository->findAll();
        $existing = [];
        foreach ($allVolumes as $vol) {
            $existing[(string)$vol->getHostPath()] = $vol;
        }

        // Create new volumes or update existing ones
        foreach ($wanted as $hostPath => $name) {
            if (isset($existing[$hostPath])) {
                $vol = $existing[$hostPath];
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
                    $this->logger->info('WatcherVolumeSync: updated volume', [
                        'host_path' => $hostPath,
                        'name' => $name,
                    ]);
                }
            } else {
                $vol = new Volume();
                $vol->setName($name);
                $vol->setHostPath($hostPath);
                // Use the same path as host_path; admins can adjust path (Docker mount) later if needed
                $vol->setPath($hostPath);
                $vol->setStatus(VolumeStatus::ACTIVE);
                $this->em->persist($vol);

                $this->logger->info('WatcherVolumeSync: created volume', [
                    'host_path' => $hostPath,
                    'name' => $name,
                ]);
            }
        }

        // Deactivate volumes no longer in watch_paths
        foreach ($existing as $hostPath => $vol) {
            if (!isset($wanted[$hostPath]) && $vol->getStatus() === VolumeStatus::ACTIVE) {
                $vol->setStatus(VolumeStatus::INACTIVE);
                $this->logger->info('WatcherVolumeSync: deactivated orphaned volume', [
                    'host_path' => $hostPath,
                ]);
            }
        }

        $this->em->flush();
    }
}
