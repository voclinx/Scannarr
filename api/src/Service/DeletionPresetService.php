<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DeletionPreset;
use App\Entity\User;
use App\Repository\DeletionPresetRepository;
use Doctrine\ORM\EntityManagerInterface;

final class DeletionPresetService
{
    public function __construct(
        private readonly DeletionPresetRepository $presetRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function list(): array
    {
        return array_map($this->serialize(...), $this->presetRepository->findAllOrderedByName());
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        $preset = $this->presetRepository->find($id);

        return $preset instanceof DeletionPreset ? $this->serialize($preset) : null;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function create(array $data, User $user): array
    {
        $name = $data['name'] ?? null;
        $criteria = $data['criteria'] ?? null;

        if (!$name || trim((string)$name) === '') {
            return ['result' => 'validation_error', 'field' => 'name', 'message' => 'Name is required'];
        }
        if ($criteria === null) {
            return ['result' => 'validation_error', 'field' => 'criteria', 'message' => 'Criteria is required'];
        }

        $preset = new DeletionPreset();
        $preset->setName(trim((string)$name));
        $preset->setCriteria($criteria);
        $preset->setFilters($data['filters'] ?? []);
        $preset->setIsSystem(false);
        $preset->setCreatedBy($user);

        if (isset($data['is_default']) && $data['is_default'] === true) {
            foreach ($this->presetRepository->findBy(['isDefault' => true]) as $existing) {
                $existing->setIsDefault(false);
            }
            $preset->setIsDefault(true);
        }

        $this->em->persist($preset);
        $this->em->flush();

        return ['result' => 'created', 'data' => $this->serialize($preset)];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{result: string, data?: array<string, mixed>}
     */
    public function update(string $id, array $data): array
    {
        $preset = $this->presetRepository->find($id);
        if (!$preset instanceof DeletionPreset) {
            return ['result' => 'not_found'];
        }
        if ($preset->isSystem()) {
            return ['result' => 'system_preset'];
        }

        if (isset($data['name'])) {
            $preset->setName(trim((string)$data['name']));
        }
        if (array_key_exists('criteria', $data)) {
            $preset->setCriteria($data['criteria']);
        }
        if (array_key_exists('filters', $data)) {
            $preset->setFilters($data['filters']);
        }
        if (isset($data['is_default']) && $data['is_default'] === true) {
            foreach ($this->presetRepository->findBy(['isDefault' => true]) as $existing) {
                if ((string)$existing->getId() !== (string)$preset->getId()) {
                    $existing->setIsDefault(false);
                }
            }
            $preset->setIsDefault(true);
        } elseif (isset($data['is_default']) && $data['is_default'] === false) {
            $preset->setIsDefault(false);
        }

        $this->em->flush();

        return ['result' => 'updated', 'data' => $this->serialize($preset)];
    }

    public function delete(string $id): string
    {
        $preset = $this->presetRepository->find($id);
        if (!$preset instanceof DeletionPreset) {
            return 'not_found';
        }
        if ($preset->isSystem()) {
            return 'system_preset';
        }

        $this->em->remove($preset);
        $this->em->flush();

        return 'deleted';
    }

    /** @return array<string, mixed> */
    private function serialize(DeletionPreset $preset): array
    {
        $createdBy = $preset->getCreatedBy();

        return [
            'id' => (string)$preset->getId(),
            'name' => $preset->getName(),
            'is_system' => $preset->isSystem(),
            'is_default' => $preset->isDefault(),
            'criteria' => $preset->getCriteria(),
            'filters' => $preset->getFilters(),
            'created_by' => $createdBy instanceof User ? [
                'id' => (string)$createdBy->getId(),
                'username' => $createdBy->getUsername(),
            ] : null,
            'created_at' => $preset->getCreatedAt()->format('c'),
            'updated_at' => $preset->getUpdatedAt()->format('c'),
        ];
    }
}
