<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Watcher;
use App\Enum\WatcherStatus;
use App\Tests\AbstractApiTestCase;

class WatcherControllerTest extends AbstractApiTestCase
{
    // ──────────────────────────────────────────────
    // Helper
    // ──────────────────────────────────────────────

    private function createWatcher(
        string $watcherId = 'test-watcher-id',
        string $name = 'Test Watcher',
        WatcherStatus $status = WatcherStatus::PENDING,
        ?string $authToken = null,
    ): Watcher {
        $watcher = new Watcher();
        $watcher->setWatcherId($watcherId);
        $watcher->setName($name);
        $watcher->setStatus($status);
        if ($authToken !== null) {
            $watcher->setAuthToken($authToken);
        }

        $this->em->persist($watcher);
        $this->em->flush();

        return $watcher;
    }

    // ──────────────────────────────────────────────
    // Access control
    // ──────────────────────────────────────────────

    public function testListRequiresAuthentication(): void
    {
        $this->apiGet('/api/v1/watchers');
        $this->assertResponseStatusCode(401);
    }

    public function testListForbiddenForNonAdmin(): void
    {
        $user = $this->createUser();
        $this->authenticateAs($user);

        $this->apiGet('/api/v1/watchers');
        $this->assertResponseStatusCode(403);
    }

    public function testAdminCanListWatchers(): void
    {
        $this->createWatcher();
        $admin = $this->createAdmin();
        $this->authenticateAs($admin);

        $this->apiGet('/api/v1/watchers');
        $this->assertResponseStatusCode(200);

        $body = $this->getResponseData();
        $this->assertArrayHasKey('data', $body);
        $this->assertIsArray($body['data']);
        $this->assertGreaterThanOrEqual(1, count($body['data']));
    }

    // ──────────────────────────────────────────────
    // GET /api/v1/watchers/{id}
    // ──────────────────────────────────────────────

    public function testGetWatcher(): void
    {
        $watcher = $this->createWatcher();
        $admin = $this->createAdmin();
        $this->authenticateAs($admin);

        $this->apiGet('/api/v1/watchers/' . $watcher->getId());
        $this->assertResponseStatusCode(200);

        $body = $this->getResponseData();
        $this->assertSame('test-watcher-id', $body['data']['watcher_id']);
        $this->assertSame('Test Watcher', $body['data']['name']);
        $this->assertSame('pending', $body['data']['status']);
        $this->assertArrayNotHasKey('auth_token', $body['data']); // Never expose token
    }

    public function testGetWatcher404(): void
    {
        $admin = $this->createAdmin();
        $this->authenticateAs($admin);

        $this->apiGet('/api/v1/watchers/00000000-0000-0000-0000-000000000000');
        $this->assertResponseStatusCode(404);
    }

    // ──────────────────────────────────────────────
    // POST /api/v1/watchers/{id}/approve
    // ──────────────────────────────────────────────

    public function testApproveWatcher(): void
    {
        $watcher = $this->createWatcher(status: WatcherStatus::PENDING);
        $admin = $this->createAdmin();
        $this->authenticateAs($admin);

        $this->apiPost('/api/v1/watchers/' . $watcher->getId() . '/approve');
        $this->assertResponseStatusCode(200);

        $body = $this->getResponseData();
        $this->assertSame('approved', $body['data']['status']);
        $this->assertArrayNotHasKey('auth_token', $body['data']); // Never expose

        // Verify token was set in DB
        $this->em->refresh($watcher);
        $this->assertNotNull($watcher->getAuthToken());
        $this->assertSame(64, strlen($watcher->getAuthToken())); // bin2hex(32) = 64 chars
    }

    public function testCannotApproveRevokedWatcher(): void
    {
        $watcher = $this->createWatcher(status: WatcherStatus::REVOKED);
        $admin = $this->createAdmin();
        $this->authenticateAs($admin);

        $this->apiPost('/api/v1/watchers/' . $watcher->getId() . '/approve');
        $this->assertResponseStatusCode(422);
    }

    // ──────────────────────────────────────────────
    // PUT /api/v1/watchers/{id}/config
    // ──────────────────────────────────────────────

    public function testUpdateConfig(): void
    {
        $watcher = $this->createWatcher(status: WatcherStatus::APPROVED, authToken: 'test-token-abc');
        $admin = $this->createAdmin();
        $this->authenticateAs($admin);

        $this->apiPut('/api/v1/watchers/' . $watcher->getId() . '/config', [
            'log_level' => 'debug',
            'watch_paths' => [['path' => '/mnt/media', 'name' => 'media']],
        ]);
        $this->assertResponseStatusCode(200);

        $body = $this->getResponseData();
        $this->assertSame('debug', $body['data']['config']['log_level']);
        $this->assertSame([['path' => '/mnt/media', 'name' => 'media']], $body['data']['config']['watch_paths']);
        // Other keys from default config should still be present (merge, not replace)
        $this->assertArrayHasKey('scan_on_start', $body['data']['config']);
    }

    // ──────────────────────────────────────────────
    // PUT /api/v1/watchers/{id}/name
    // ──────────────────────────────────────────────

    public function testUpdateName(): void
    {
        $watcher = $this->createWatcher();
        $admin = $this->createAdmin();
        $this->authenticateAs($admin);

        $this->apiPut('/api/v1/watchers/' . $watcher->getId() . '/name', ['name' => 'New Name']);
        $this->assertResponseStatusCode(200);

        $body = $this->getResponseData();
        $this->assertSame('New Name', $body['data']['name']);
    }

    public function testUpdateNameRequiresNonEmpty(): void
    {
        $watcher = $this->createWatcher();
        $admin = $this->createAdmin();
        $this->authenticateAs($admin);

        $this->apiPut('/api/v1/watchers/' . $watcher->getId() . '/name', ['name' => '   ']);
        $this->assertResponseStatusCode(422);
    }

    // ──────────────────────────────────────────────
    // POST /api/v1/watchers/{id}/revoke
    // ──────────────────────────────────────────────

    public function testRevokeWatcher(): void
    {
        $watcher = $this->createWatcher(status: WatcherStatus::APPROVED, authToken: 'abc123token');
        $admin = $this->createAdmin();
        $this->authenticateAs($admin);

        $this->apiPost('/api/v1/watchers/' . $watcher->getId() . '/revoke');
        $this->assertResponseStatusCode(200);

        $body = $this->getResponseData();
        $this->assertSame('revoked', $body['data']['status']);

        // Verify token was cleared in DB
        $this->em->refresh($watcher);
        $this->assertNull($watcher->getAuthToken());
    }

    // ──────────────────────────────────────────────
    // DELETE /api/v1/watchers/{id}
    // ──────────────────────────────────────────────

    public function testDeleteWatcher(): void
    {
        $watcher = $this->createWatcher();
        $watcherId = (string)$watcher->getId();
        $admin = $this->createAdmin();
        $this->authenticateAs($admin);

        $this->apiDelete('/api/v1/watchers/' . $watcherId);
        $this->assertResponseStatusCode(204);

        // Verify gone from DB
        $found = $this->em->getRepository(Watcher::class)->find($watcherId);
        $this->assertNull($found);
    }

    // ──────────────────────────────────────────────
    // GET /api/v1/watchers/{id}/logs
    // ──────────────────────────────────────────────

    public function testLogsAccessibleToAdvancedUser(): void
    {
        $watcher = $this->createWatcher();
        $advanced = $this->createAdvancedUser();
        $this->authenticateAs($advanced);

        $this->apiGet('/api/v1/watchers/' . $watcher->getId() . '/logs');
        $this->assertResponseStatusCode(200);

        $body = $this->getResponseData();
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('meta', $body);
        $this->assertArrayHasKey('total', $body['meta']);
    }

    public function testLogsNotAccessibleToGuest(): void
    {
        $watcher = $this->createWatcher();
        $guest = $this->createGuest();
        $this->authenticateAs($guest);

        $this->apiGet('/api/v1/watchers/' . $watcher->getId() . '/logs');
        $this->assertResponseStatusCode(403);
    }

    // ──────────────────────────────────────────────
    // POST /api/v1/watchers/{id}/debug
    // ──────────────────────────────────────────────

    public function testToggleDebugEnablesDebugLevel(): void
    {
        $watcher = $this->createWatcher(status: WatcherStatus::APPROVED, authToken: 'debug-token');
        $admin = $this->createAdmin();
        $this->authenticateAs($admin);

        // Default level is 'info' — toggling should set 'debug'
        $this->apiPost('/api/v1/watchers/' . $watcher->getId() . '/debug');
        $this->assertResponseStatusCode(200);

        $body = $this->getResponseData();
        $this->assertSame('debug', $body['data']['config']['log_level']);
    }

    public function testToggleDebugDisablesDebugLevel(): void
    {
        $watcher = $this->createWatcher(status: WatcherStatus::APPROVED, authToken: 'debug-token-2');
        // Pre-set log level to debug
        $watcher->mergeConfig(['log_level' => 'debug']);
        $this->em->flush();

        $admin = $this->createAdmin();
        $this->authenticateAs($admin);

        // Level is 'debug' — toggling should set 'info'
        $this->apiPost('/api/v1/watchers/' . $watcher->getId() . '/debug');
        $this->assertResponseStatusCode(200);

        $body = $this->getResponseData();
        $this->assertSame('info', $body['data']['config']['log_level']);
    }
}
