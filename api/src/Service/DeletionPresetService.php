<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DeletionPreset;
use App\Entity\User;
use App\Repository\DeletionPresetRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DeletionPresetService
{
    public function __construct(
        private DeletionPresetRepository $presetRepository,
        private EntityManagerInterface $em,
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
        $validationError = $this->validatePresetData($data);
        if ($validationError !== null) {
            return $validationError;
        }

        $preset = new DeletionPreset();
        $preset->setName(trim((string)$data['name']));
        $preset->setCriteria($data['criteria']);
        $preset->setFilters($data['filters'] ?? []);
        $preset->setIsSystem(false);
        $preset->setCreatedBy($user);

        $this->applyIsDefault($preset, $data);

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

        $this->applyPresetFields($preset, $data);
        $this->applyIsDefault($preset, $data);

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

    /**
     * @param array<string, mixed> $data
     *
     * @return array{result: string, field: string, message: string}|null
     */
    private function validatePresetData(array $data): ?array
    {
        $name = $data['name'] ?? null;
        if (!$name || trim((string)$name) === '') {
            return ['result' => 'validation_error', 'field' => 'name', 'message' => 'Name is required'];
        }

        if (!array_key_exists('criteria', $data) || $data['criteria'] === null) {
            return ['result' => 'validation_error', 'field' => 'criteria', 'message' => 'Criteria is required'];
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private function applyPresetFields(DeletionPreset $preset, array $data): void
    {
        if (isset($data['name'])) {
            $preset->setName(trim((string)$data['name']));
        }
        if (array_key_exists('criteria', $data)) {
            $preset->setCriteria($data['criteria']);
        }
        if (array_key_exists('filters', $data)) {
            $preset->setFilters($data['filters']);
        }
    }

    /** @param array<string, mixed> $data */
    private function applyIsDefault(DeletionPreset $preset, array $data): void
    {
        if (!isset($data['is_default'])) {
            return;
        }

        if ($data['is_default'] !== true) {
            $preset->setIsDefault(false);

            return;
        }

        foreach ($this->presetRepository->findBy(['isDefault' => true]) as $existing) {
            if ((string)$existing->getId() !== (string)$preset->getId()) {
                $existing->setIsDefault(false);
            }
        }
        $preset->setIsDefault(true);
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
