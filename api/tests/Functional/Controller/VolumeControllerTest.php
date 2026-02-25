<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Volume;
use App\Enum\VolumeStatus;
use App\Enum\VolumeType;
use App\Tests\AbstractApiTestCase;

class VolumeControllerTest extends AbstractApiTestCase
{
    // -----------------------------------------------------------------------
    // TEST-VOL-001 : Créer un volume - succès
    // -----------------------------------------------------------------------

    /**
     * @testdox TEST-VOL-001 Créer un volume - succès
     */
    public function testCreateVolumeSuccess(): void
    {
        $admin = $this->createAdmin();
        $this->authenticateAs($admin);

        $tmpPath = sys_get_temp_dir();

        $this->apiPost('/api/v1/volumes', [
            'name' => 'Test Volume',
            'path' => $tmpPath,
            'host_path' => '/mnt/host/media',
            'type' => 'local',
        ]);

        $this->assertResponseStatusCode(201);

        $data = $this->getResponseData();
        $this->assertArrayHasKey('data', $data);
        $this->assertEquals('Test Volume', $data['data']['name']);
        $this->assertEquals($tmpPath, $data['data']['path']);
        $this->assertEquals('/mnt/host/media', $data['data']['host_path']);
        $this->assertEquals('local', $data['data']['type']);
        $this->assertEquals('active', $data['data']['status']);
        $this->assertNotEmpty($data['data']['id']);
        $this->assertNotEmpty($data['data']['created_at']);
        $this->assertNotEmpty($data['data']['updated_at']);
    }

    // -----------------------------------------------------------------------
    // TEST-VOL-002 : Créer un volume - chemin dupliqué
    // -----------------------------------------------------------------------

    /**
     * @testdox TEST-VOL-002 Créer un volume - chemin dupliqué
     */
    public function testCreateVolumeDuplicatePath(): void
    {
        $admin = $this->createAdmin();
        $this->authenticateAs($admin);

        $tmpPath = sys_get_temp_dir();

        // Create the first volume directly in DB
        $volume = new Volume();
        $volume->setName('Existing Volume');
        $volume->setPath($tmpPath);
        $volume->setHostPath('/mnt/host/existing');
        $volume->setType(VolumeType::LOCAL);
        $volume->setStatus(VolumeStatus::ACTIVE);
        $this->em->persist($volume);
        $this->em->flush();

        // Attempt to create a second volume with the same path
        $this->apiPost('/api/v1/volumes', [
            'name' => 'Duplicate Volume',
            'path' => $tmpPath,
            'host_path' => '/mnt/host/duplicate',
            'type' => 'local',
        ]);

        $this->assertResponseStatusCode(409);

        $data = $this->getResponseData();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('already exists', $data['error']['message']);
    }

    // -----------------------------------------------------------------------
    // TEST-VOL-003 : Créer un volume - chemin inexistant sur le filesystem
    // -----------------------------------------------------------------------

    /**
     * @testdox TEST-VOL-003 Créer un volume - chemin inexistant sur le filesystem
     */
    public function testCreateVolumeNonexistentPath(): void
    {
        $admin = $this->createAdmin();
        $this->authenticateAs($admin);

        $this->apiPost('/api/v1/volumes', [
            'name' => 'Bad Volume',
            'path' => '/nonexistent/path',
            'host_path' => '/mnt/host/bad',
            'type' => 'local',
        ]);

        $this->assertResponseStatusCode(422);

        $data = $this->getResponseData();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('not accessible', $data['error']['message']);
    }

    // -----------------------------------------------------------------------
    // TEST-VOL-004 : Déclencher un scan
    // -----------------------------------------------------------------------

    /**
     * @testdox TEST-VOL-004 Déclencher un scan
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

        $volumeId = (string) $volume->getId();

        $this->apiPost("/api/v1/volumes/{$volumeId}/scan");

        $this->assertResponseStatusCode(202);

        $data = $this->getResponseData();
        $this->assertArrayHasKey('data', $data);
        $this->assertStringContainsString('Scan initiated', $data['data']['message']);
        $this->assertEquals($volumeId, $data['data']['volume_id']);
        $this->assertNotEmpty($data['data']['scan_id']);
    }

    // -----------------------------------------------------------------------
    // TEST-VOL-005 : Supprimer un volume
    // -----------------------------------------------------------------------

    /**
     * @testdox TEST-VOL-005 Supprimer un volume
     */
    public function testDeleteVolume(): void
    {
        $admin = $this->createAdmin();
        $this->authenticateAs($admin);

        $tmpPath = sys_get_temp_dir();

        // Create a volume directly in DB
        $volume = new Volume();
        $volume->setName('Delete Me');
        $volume->setPath($tmpPath);
        $volume->setHostPath('/mnt/host/delete');
        $volume->setType(VolumeType::LOCAL);
        $volume->setStatus(VolumeStatus::ACTIVE);
        $this->em->persist($volume);
        $this->em->flush();

        $volumeId = (string) $volume->getId();

        $this->apiDelete("/api/v1/volumes/{$volumeId}");

        $this->assertResponseStatusCode(204);

        // Verify the volume is gone from DB
        $this->em->clear();
        $deleted = $this->em->getRepository(Volume::class)->find($volumeId);
        $this->assertNull($deleted);
    }
}
