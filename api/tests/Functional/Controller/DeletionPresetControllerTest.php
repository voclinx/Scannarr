<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\DeletionPreset;
use App\Tests\AbstractApiTestCase;

class DeletionPresetControllerTest extends AbstractApiTestCase
{
    private function createPreset(
        string $name = 'Test Preset',
        bool $isSystem = false,
        bool $isDefault = false,
        array $criteria = ['ratio' => ['weight' => 30, 'threshold' => 1.0]],
        array $filters = [],
    ): DeletionPreset {
        $preset = new DeletionPreset();
        $preset->setName($name);
        $preset->setIsSystem($isSystem);
        $preset->setIsDefault($isDefault);
        $preset->setCriteria($criteria);
        $preset->setFilters($filters);
        $this->em->persist($preset);
        $this->em->flush();

        return $preset;
    }

    // -----------------------------------------------------------------------
    // List presets
    // -----------------------------------------------------------------------

    public function testListPresetsAsUser(): void
    {
        $user = $this->createUser();
        $this->authenticateAs($user);

        $this->createPreset('Alpha');
        $this->createPreset('Beta');

        $this->apiGet('/api/v1/deletion-presets');
        $this->assertResponseStatusCode(200);

        $response = $this->getResponseData();
        $this->assertArrayHasKey('data', $response);
        $this->assertGreaterThanOrEqual(2, count($response['data']));

        // Verify alphabetical ordering
        $names = array_column($response['data'], 'name');
        $sorted = $names;
        sort($sorted);
        $this->assertEquals($sorted, $names);
    }

    public function testListPresetsUnauthenticated(): void
    {
        $this->apiGet('/api/v1/deletion-presets');
        $this->assertResponseStatusCode(401);
    }

    // -----------------------------------------------------------------------
    // Create preset
    // -----------------------------------------------------------------------

    public function testCreatePresetAsAdvancedUser(): void
    {
        $user = $this->createAdvancedUser();
        $this->authenticateAs($user);

        $this->apiPost('/api/v1/deletion-presets', [
            'name' => 'My Custom Preset',
            'criteria' => ['ratio' => ['weight' => 25, 'threshold' => 2.0]],
            'filters' => ['min_size_gb' => 10],
        ]);

        $this->assertResponseStatusCode(201);

        $response = $this->getResponseData();
        $data = $response['data'];
        $this->assertEquals('My Custom Preset', $data['name']);
        $this->assertFalse($data['is_system']);
        $this->assertFalse($data['is_default']);
        $this->assertEquals(['ratio' => ['weight' => 25, 'threshold' => 2.0]], $data['criteria']);
        $this->assertEquals(['min_size_gb' => 10], $data['filters']);
        $this->assertNotNull($data['created_by']);
        $this->assertEquals('advanced', $data['created_by']['username']);
    }

    public function testCreatePresetAsRegularUserForbidden(): void
    {
        $user = $this->createUser();
        $this->authenticateAs($user);

        $this->apiPost('/api/v1/deletion-presets', [
            'name' => 'Should Fail',
            'criteria' => ['ratio' => ['weight' => 30]],
        ]);

        $this->assertResponseStatusCode(403);
    }

    public function testCreatePresetMissingName(): void
    {
        $user = $this->createAdvancedUser();
        $this->authenticateAs($user);

        $this->apiPost('/api/v1/deletion-presets', [
            'criteria' => ['ratio' => ['weight' => 30]],
        ]);

        $this->assertResponseStatusCode(422);
        $response = $this->getResponseData();
        $this->assertArrayHasKey('error', $response);
    }

    public function testCreatePresetMissingCriteria(): void
    {
        $user = $this->createAdvancedUser();
        $this->authenticateAs($user);

        $this->apiPost('/api/v1/deletion-presets', [
            'name' => 'No Criteria',
        ]);

        $this->assertResponseStatusCode(422);
    }

    // -----------------------------------------------------------------------
    // Get preset detail
    // -----------------------------------------------------------------------

    public function testGetPresetDetail(): void
    {
        $user = $this->createUser();
        $this->authenticateAs($user);

        $preset = $this->createPreset('Detail Test');
        $presetId = (string)$preset->getId();

        $this->em->clear();

        $this->apiGet("/api/v1/deletion-presets/{$presetId}");
        $this->assertResponseStatusCode(200);

        $response = $this->getResponseData();
        $this->assertEquals('Detail Test', $response['data']['name']);
    }

    public function testGetPresetNotFound(): void
    {
        $user = $this->createUser();
        $this->authenticateAs($user);

        $this->apiGet('/api/v1/deletion-presets/019c0000-0000-0000-0000-000000000000');
        $this->assertResponseStatusCode(404);
    }

    // -----------------------------------------------------------------------
    // Update preset
    // -----------------------------------------------------------------------

    public function testUpdatePreset(): void
    {
        $user = $this->createAdvancedUser();
        $this->authenticateAs($user);

        $preset = $this->createPreset('Before Update');
        $presetId = (string)$preset->getId();

        $this->apiPut("/api/v1/deletion-presets/{$presetId}", [
            'name' => 'After Update',
            'criteria' => ['seed_time' => ['weight' => 40]],
        ]);

        $this->assertResponseStatusCode(200);

        $response = $this->getResponseData();
        $this->assertEquals('After Update', $response['data']['name']);
        $this->assertEquals(['seed_time' => ['weight' => 40]], $response['data']['criteria']);
    }

    public function testUpdateSystemPresetForbidden(): void
    {
        $user = $this->createAdvancedUser();
        $this->authenticateAs($user);

        $preset = $this->createPreset('System Preset', isSystem: true);
        $presetId = (string)$preset->getId();

        $this->apiPut("/api/v1/deletion-presets/{$presetId}", [
            'name' => 'Hacked',
        ]);

        $this->assertResponseStatusCode(403);
    }

    public function testUpdatePresetIsDefaultUniqueConstraint(): void
    {
        $user = $this->createAdvancedUser();
        $this->authenticateAs($user);

        $presetA = $this->createPreset('Preset A', isDefault: true);
        $presetB = $this->createPreset('Preset B');
        $presetBId = (string)$presetB->getId();
        $presetAId = (string)$presetA->getId();

        $this->apiPut("/api/v1/deletion-presets/{$presetBId}", [
            'is_default' => true,
        ]);

        $this->assertResponseStatusCode(200);
        $response = $this->getResponseData();
        $this->assertTrue($response['data']['is_default']);

        // Verify old default was unset
        $this->em->clear();
        $refreshedA = $this->em->find(DeletionPreset::class, $presetAId);
        $this->assertFalse($refreshedA->isDefault());
    }

    // -----------------------------------------------------------------------
    // Delete preset
    // -----------------------------------------------------------------------

    public function testDeletePreset(): void
    {
        $user = $this->createAdvancedUser();
        $this->authenticateAs($user);

        $preset = $this->createPreset('To Delete');
        $presetId = (string)$preset->getId();

        $this->apiDelete("/api/v1/deletion-presets/{$presetId}");
        $this->assertResponseStatusCode(204);

        $this->em->clear();
        $deleted = $this->em->find(DeletionPreset::class, $presetId);
        $this->assertNull($deleted);
    }

    public function testDeleteSystemPresetForbidden(): void
    {
        $user = $this->createAdvancedUser();
        $this->authenticateAs($user);

        $preset = $this->createPreset('System', isSystem: true);
        $presetId = (string)$preset->getId();

        $this->apiDelete("/api/v1/deletion-presets/{$presetId}");
        $this->assertResponseStatusCode(403);
    }
}
