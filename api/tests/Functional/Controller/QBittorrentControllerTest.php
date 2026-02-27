<?php

namespace App\Tests\Functional\Controller;

use App\Tests\AbstractApiTestCase;

class QBittorrentControllerTest extends AbstractApiTestCase
{
    // -----------------------------------------------------------------------
    // TEST-QBIT-001 : POST /api/v1/qbittorrent/sync as ADMIN — 200
    // -----------------------------------------------------------------------

    /**
     * @testdox TEST-QBIT-001 POST /api/v1/qbittorrent/sync as ADMIN returns 200
     */
    public function testSyncAsAdmin(): void
    {
        $admin = $this->createAdmin();
        $this->authenticateAs($admin);

        // qBit not configured — sync returns gracefully with zero counts
        $this->apiPost('/api/v1/qbittorrent/sync');

        $this->assertResponseStatusCode(200);

        $data = $this->getResponseData();
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('torrents_synced', $data['data']);
        $this->assertArrayHasKey('new_trackers', $data['data']);
        $this->assertArrayHasKey('unmatched', $data['data']);
        $this->assertArrayHasKey('errors', $data['data']);
        $this->assertArrayHasKey('stale_removed', $data['data']);
    }

    // -----------------------------------------------------------------------
    // TEST-QBIT-002 : GET /api/v1/qbittorrent/sync/status — 200
    // -----------------------------------------------------------------------

    /**
     * @testdox TEST-QBIT-002 GET /api/v1/qbittorrent/sync/status returns last sync info
     */
    public function testSyncStatus(): void
    {
        $admin = $this->createAdmin();
        $this->authenticateAs($admin);

        $this->apiGet('/api/v1/qbittorrent/sync/status');

        $this->assertResponseStatusCode(200);

        $data = $this->getResponseData();
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('last_sync_at', $data['data']);
        $this->assertArrayHasKey('last_result', $data['data']);
    }

    // -----------------------------------------------------------------------
    // TEST-QBIT-003 : POST /api/v1/qbittorrent/sync as USER — 403
    // -----------------------------------------------------------------------

    /**
     * @testdox TEST-QBIT-003 POST /api/v1/qbittorrent/sync as regular USER returns 403
     */
    public function testSyncAsUserForbidden(): void
    {
        $user = $this->createUser();
        $this->authenticateAs($user);

        $this->apiPost('/api/v1/qbittorrent/sync');

        $this->assertResponseStatusCode(403);
    }

    // -----------------------------------------------------------------------
    // TEST-QBIT-004 : POST /api/v1/qbittorrent/sync unauthenticated — 401
    // -----------------------------------------------------------------------

    /**
     * @testdox TEST-QBIT-004 POST /api/v1/qbittorrent/sync unauthenticated returns 401
     */
    public function testSyncUnauthenticated(): void
    {
        $this->apiPost('/api/v1/qbittorrent/sync');

        $this->assertResponseStatusCode(401);
    }

    // -----------------------------------------------------------------------
    // TEST-QBIT-005 : GET /api/v1/qbittorrent/sync/status as USER — 403
    // -----------------------------------------------------------------------

    /**
     * @testdox TEST-QBIT-005 GET /api/v1/qbittorrent/sync/status as USER returns 403
     */
    public function testSyncStatusAsUserForbidden(): void
    {
        $user = $this->createUser();
        $this->authenticateAs($user);

        $this->apiGet('/api/v1/qbittorrent/sync/status');

        $this->assertResponseStatusCode(403);
    }

    // -----------------------------------------------------------------------
    // TEST-QBIT-006 : Sync then check status has data
    // -----------------------------------------------------------------------

    /**
     * @testdox TEST-QBIT-006 Sync then check status returns updated data
     */
    public function testSyncStatusWithExistingData(): void
    {
        $admin = $this->createAdmin();
        $this->authenticateAs($admin);

        // Set up sync result data directly in settings
        $this->setSetting('qbittorrent_last_sync_at', '2026-02-27T10:00:00+00:00');
        $this->setSetting('qbittorrent_last_sync_result', '{"torrents_synced":5,"new_trackers":1,"unmatched":2,"stale_removed":0,"errors":0}', 'json');

        $this->apiGet('/api/v1/qbittorrent/sync/status');
        $this->assertResponseStatusCode(200);

        $data = $this->getResponseData();
        $this->assertArrayHasKey('data', $data);
        $this->assertSame('2026-02-27T10:00:00+00:00', $data['data']['last_sync_at']);
        $this->assertSame(5, $data['data']['last_result']['torrents_synced']);
    }
}
