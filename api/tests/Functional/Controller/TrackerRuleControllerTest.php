<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\TrackerRule;
use App\Tests\AbstractApiTestCase;

class TrackerRuleControllerTest extends AbstractApiTestCase
{
    private function createTrackerRule(
        string $domain = 'tracker-a.com',
        int $minSeedTimeHours = 720,
        string $minRatio = '1.0000',
    ): TrackerRule {
        $rule = new TrackerRule();
        $rule->setTrackerDomain($domain);
        $rule->setMinSeedTimeHours($minSeedTimeHours);
        $rule->setMinRatio($minRatio);
        $this->em->persist($rule);
        $this->em->flush();

        return $rule;
    }

    // -----------------------------------------------------------------------
    // List tracker rules
    // -----------------------------------------------------------------------

    public function testListTrackerRulesAsAdvancedUser(): void
    {
        $user = $this->createAdvancedUser();
        $this->authenticateAs($user);

        $this->createTrackerRule('aaa-tracker.org', 500, '0.5000');
        $this->createTrackerRule('zzz-tracker.net', 1000, '2.0000');

        $this->apiGet('/api/v1/tracker-rules');
        $this->assertResponseStatusCode(200);

        $response = $this->getResponseData();
        $this->assertArrayHasKey('data', $response);
        $this->assertGreaterThanOrEqual(2, count($response['data']));

        // Verify ordering by domain
        $domains = array_column($response['data'], 'tracker_domain');
        $sorted = $domains;
        sort($sorted);
        $this->assertEquals($sorted, $domains);

        // Verify serialization format
        $first = $response['data'][0];
        $this->assertArrayHasKey('id', $first);
        $this->assertArrayHasKey('tracker_domain', $first);
        $this->assertArrayHasKey('min_seed_time_hours', $first);
        $this->assertArrayHasKey('min_ratio', $first);
        $this->assertArrayHasKey('is_auto_detected', $first);
        $this->assertArrayHasKey('created_at', $first);
        $this->assertArrayHasKey('updated_at', $first);
    }

    public function testListTrackerRulesAsRegularUserForbidden(): void
    {
        $user = $this->createUser();
        $this->authenticateAs($user);

        $this->apiGet('/api/v1/tracker-rules');
        $this->assertResponseStatusCode(403);
    }

    // -----------------------------------------------------------------------
    // Update tracker rule
    // -----------------------------------------------------------------------

    public function testUpdateTrackerRuleAsAdmin(): void
    {
        $admin = $this->createAdmin();
        $this->authenticateAs($admin);

        $rule = $this->createTrackerRule('tracker-b.com', 720, '1.0000');
        $ruleId = (string)$rule->getId();

        $this->apiPut("/api/v1/tracker-rules/{$ruleId}", [
            'min_seed_time_hours' => 1440,
            'min_ratio' => '2.5000',
        ]);

        $this->assertResponseStatusCode(200);

        $response = $this->getResponseData();
        $this->assertEquals(1440, $response['data']['min_seed_time_hours']);
        $this->assertEquals('2.5000', $response['data']['min_ratio']);
    }

    public function testUpdateTrackerRuleAsAdvancedUserForbidden(): void
    {
        $user = $this->createAdvancedUser();
        $this->authenticateAs($user);

        $rule = $this->createTrackerRule('tracker-c.com');
        $ruleId = (string)$rule->getId();

        $this->apiPut("/api/v1/tracker-rules/{$ruleId}", [
            'min_seed_time_hours' => 100,
        ]);

        $this->assertResponseStatusCode(403);
    }

    public function testUpdateTrackerRuleNotFound(): void
    {
        $admin = $this->createAdmin();
        $this->authenticateAs($admin);

        $this->apiPut('/api/v1/tracker-rules/019c0000-0000-0000-0000-000000000000', [
            'min_seed_time_hours' => 100,
        ]);

        $this->assertResponseStatusCode(404);
    }

    public function testUpdateTrackerRulePartialUpdate(): void
    {
        $admin = $this->createAdmin();
        $this->authenticateAs($admin);

        $rule = $this->createTrackerRule('tracker-d.com', 720, '1.0000');
        $ruleId = (string)$rule->getId();

        // Only update min_ratio, min_seed_time_hours should stay
        $this->apiPut("/api/v1/tracker-rules/{$ruleId}", [
            'min_ratio' => '3.0000',
        ]);

        $this->assertResponseStatusCode(200);

        $response = $this->getResponseData();
        $this->assertEquals(720, $response['data']['min_seed_time_hours']);
        $this->assertEquals('3.0000', $response['data']['min_ratio']);
    }
}
