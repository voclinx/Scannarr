<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Watcher;
use App\Enum\WatcherStatus;
use PHPUnit\Framework\TestCase;

class WatcherTest extends TestCase
{
    public function testDefaultConfig(): void
    {
        $watcher = new Watcher();
        $config = $watcher->getConfig();

        $this->assertIsArray($config);
        $this->assertArrayHasKey('watch_paths', $config);
        $this->assertArrayHasKey('scan_on_start', $config);
        $this->assertArrayHasKey('log_level', $config);
        $this->assertArrayHasKey('reconnect_delay', $config);
        $this->assertArrayHasKey('ping_interval', $config);
        $this->assertArrayHasKey('log_retention_days', $config);
        $this->assertArrayHasKey('debug_log_retention_hours', $config);

        $this->assertSame([], $config['watch_paths']);
        $this->assertTrue($config['scan_on_start']);
        $this->assertSame('info', $config['log_level']);
    }

    public function testDefaultStatus(): void
    {
        $watcher = new Watcher();
        $this->assertSame(WatcherStatus::PENDING, $watcher->getStatus());
    }

    public function testComputeConfigHash(): void
    {
        $watcher = new Watcher();
        $hash = $watcher->computeConfigHash();

        $this->assertNotEmpty($hash);
        $this->assertSame(64, strlen($hash)); // SHA-256 hex = 64 chars
    }

    public function testConfigHashChangesOnMerge(): void
    {
        $watcher = new Watcher();
        $originalHash = $watcher->getConfigHash();

        $watcher->mergeConfig(['log_level' => 'debug']);

        $this->assertNotSame($originalHash, $watcher->getConfigHash());
    }

    public function testConfigHashIsStable(): void
    {
        $watcher = new Watcher();

        // Same config → same hash
        $hash1 = $watcher->computeConfigHash();
        $hash2 = $watcher->computeConfigHash();

        $this->assertSame($hash1, $hash2);
    }

    public function testConfigHashIsOrderIndependent(): void
    {
        $watcher1 = new Watcher();
        $watcher1->setConfig(['a' => 1, 'b' => 2]);

        $watcher2 = new Watcher();
        $watcher2->setConfig(['b' => 2, 'a' => 1]);

        $this->assertSame($watcher1->getConfigHash(), $watcher2->getConfigHash());
    }

    public function testMergeConfigPreservesExistingKeys(): void
    {
        $watcher = new Watcher();
        $watcher->mergeConfig(['log_level' => 'debug']);

        $config = $watcher->getConfig();
        $this->assertSame('debug', $config['log_level']);
        $this->assertArrayHasKey('watch_paths', $config); // Original keys preserved
    }

    public function testSetConfigUpdatesHash(): void
    {
        $watcher = new Watcher();
        $originalHash = $watcher->getConfigHash();

        $watcher->setConfig(['watch_paths' => ['/mnt/media']]);

        $this->assertNotSame($originalHash, $watcher->getConfigHash());
        $this->assertSame(['/mnt/media'], $watcher->getConfig()['watch_paths']);
    }

    public function testAuthTokenStartsNull(): void
    {
        $watcher = new Watcher();
        $this->assertNull($watcher->getAuthToken());
    }
}
