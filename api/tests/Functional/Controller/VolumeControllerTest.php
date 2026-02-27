<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Volume;
use App\Enum\VolumeStatus;
use App\Enum\VolumeType;
use App\Tests\AbstractApiTestCase;

class VolumeControllerTest extends AbstractApiTestCase
{
    // -----------------------------------------------------------------------
    // TEST-VOL-001 : Lister les volumes
    // -----------------------------------------------------------------------

    /**
     * @testdox TEST-VOL-001 Lister les volumes (Guest+)
     */
    public function testListVolumes(): void
    {
        $guest = $this->createGuest();
        $this->authenticateAs($guest);

        $this->apiGet('/api/v1/volumes');
        $this->assertResponseStatusCode(200);

        $data = $this->getResponseData();
        $this->assertArrayHasKey('data', $data);
        $this->assertIsArray($data['data']);
    }

    // -----------------------------------------------------------------------
    // TEST-VOL-002 : Déclencher un scan
    // -----------------------------------------------------------------------

    /**
     * @testdox TEST-VOL-002 Déclencher un scan
     */
    public function testTriggerVolumeScan(): void
    {
        $admin = $this->createAdmin();
        $this->authenticateAs($admin);

        $tmpPath = sys_get_temp_dir();

        // Create a volume directly in DB
        $volume = new Volume();
        $volume->setName('Scan Volume');
        $volume->setPath($tmpPath);
        $volume->setHostPath('/mnt/host/scan');
        $volume->setType(VolumeType::LOCAL);
        $volume->setStatus(VolumeStatus::ACTIVE);
        $this->em->persist($volume);
        $this->em->flush();

        $volumeId = (string)$volume->getId();

        $this->apiPost("/api/v1/volumes/{$volumeId}/scan");

        $this->assertResponseStatusCode(202);

        $data = $this->getResponseData();
        $this->assertArrayHasKey('data', $data);
        $this->assertStringContainsString('Scan initiated', $data['data']['message']);
        $this->assertEquals($volumeId, $data['data']['volume_id']);
        $this->assertNotEmpty($data['data']['scan_id']);
    }

    // -----------------------------------------------------------------------
    // TEST-VOL-003 : Les endpoints create/delete/update sont supprimés
    // -----------------------------------------------------------------------

    /**
     * @testdox TEST-VOL-003 POST /volumes (create) n'existe plus — 405
     */
    public function testCreateVolumeEndpointRemoved(): void
    {
        $admin = $this->createAdmin();
        $this->authenticateAs($admin);

        $this->apiPost('/api/v1/volumes', ['name' => 'x', 'path' => '/tmp', 'host_path' => '/tmp', 'type' => 'local']);
        $this->assertResponseStatusCode(405);
    }

    /**
     * @testdox TEST-VOL-004 DELETE /volumes/{id} (delete) n'existe plus — 405
     */
    public function testDeleteVolumeEndpointRemoved(): void
    {
        $admin = $this->createAdmin();
        $this->authenticateAs($admin);

        $tmpPath = sys_get_temp_dir();
        $volume = new Volume();
        $volume->setName('To check');
        $volume->setPath($tmpPath);
        $volume->setHostPath('/mnt/host/check');
        $volume->setType(VolumeType::LOCAL);
        $volume->setStatus(VolumeStatus::ACTIVE);
        $this->em->persist($volume);
        $this->em->flush();

        $this->apiDelete("/api/v1/volumes/{$volume->getId()}");
        $this->assertResponseStatusCode(404);
    }
}
