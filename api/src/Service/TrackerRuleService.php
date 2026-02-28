<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\TrackerRule;
use App\Repository\TrackerRuleRepository;
use Doctrine\ORM\EntityManagerInterface;

final class TrackerRuleService
{
    public function __construct(
        private readonly TrackerRuleRepository $trackerRuleRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function list(): array
    {
        return array_map($this->serialize(...), $this->trackerRuleRepository->findAllOrderedByDomain());
    }

    /**
     * Update a tracker rule. Returns serialized rule or null if not found.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>|null
     */
    public function update(string $id, array $data): ?array
    {
        $rule = $this->trackerRuleRepository->find($id);
        if (!$rule instanceof TrackerRule) {
            return null;
        }

        if (isset($data['min_seed_time_hours'])) {
            $rule->setMinSeedTimeHours((int)$data['min_seed_time_hours']);
        }
        if (isset($data['min_ratio'])) {
            $rule->setMinRatio((string)$data['min_ratio']);
        }

        $this->em->flush();

        return $this->serialize($rule);
    }

    /** @return array<string, mixed> */
    private function serialize(TrackerRule $rule): array
    {
        return [
            'id' => (string)$rule->getId(),
            'tracker_domain' => $rule->getTrackerDomain(),
            'min_seed_time_hours' => $rule->getMinSeedTimeHours(),
            'min_ratio' => $rule->getMinRatio(),
            'is_auto_detected' => $rule->isAutoDetected(),
            'created_at' => $rule->getCreatedAt()->format('c'),
            'updated_at' => $rule->getUpdatedAt()->format('c'),
        ];
    }
}
