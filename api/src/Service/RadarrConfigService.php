<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\RadarrInstance;
use App\ExternalService\MediaManager\RadarrService;
use App\Repository\RadarrInstanceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class RadarrConfigService
{
    public function __construct(
        private RadarrInstanceRepository $radarrRepository,
        private EntityManagerInterface $em,
        private ValidatorInterface $validator,
        private RadarrService $radarrService,
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function list(): array
    {
        $instances = $this->radarrRepository->findAll();

        return array_map($this->serialize(...), $instances);
    }

    public function find(string $id): ?RadarrInstance
    {
        return $this->radarrRepository->find($id);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{result: string, data?: array<string, mixed>, errors?: array<string, string>}
     */
    public function create(array $data): array
    {
        $instance = new RadarrInstance();
        $instance->setName($data['name'] ?? '');
        $instance->setUrl($data['url'] ?? '');
        $instance->setApiKey($data['api_key'] ?? '');
        if (isset($data['is_active'])) {
            $instance->setIsActive((bool)$data['is_active']);
        }

        $errors = $this->validator->validate($instance);
        if (count($errors) > 0) {
            $details = [];
            foreach ($errors as $error) {
                $details[$error->getPropertyPath()] = $error->getMessage();
            }

            return ['result' => 'validation_error', 'errors' => $details];
        }

        $this->em->persist($instance);
        $this->em->flush();

        return ['result' => 'created', 'data' => $this->serialize($instance)];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{result: string, data?: array<string, mixed>, errors?: array<string, string>}|null
     */
    public function update(string $id, array $data): ?array
    {
        $instance = $this->radarrRepository->find($id);
        if (!$instance instanceof RadarrInstance) {
            return null;
        }

        $this->applyInstanceFields($instance, $data);

        $validationResult = $this->validateInstance($instance);
        if ($validationResult !== null) {
            return $validationResult;
        }

        $this->em->flush();

        return ['result' => 'updated', 'data' => $this->serialize($instance)];
    }

    /** @param array<string, mixed> $data */
    private function applyInstanceFields(RadarrInstance $instance, array $data): void
    {
        if (isset($data['name'])) {
            $instance->setName($data['name']);
        }
        if (isset($data['url'])) {
            $instance->setUrl($data['url']);
        }
        if (!empty($data['api_key']) && is_string($data['api_key']) && !str_starts_with($data['api_key'], '••••')) {
            $instance->setApiKey($data['api_key']);
        }
        if (isset($data['is_active'])) {
            $instance->setIsActive((bool)$data['is_active']);
        }
    }

    /** @return array{result: string, errors: array<string, string>}|null */
    private function validateInstance(RadarrInstance $instance): ?array
    {
        $errors = $this->validator->validate($instance);
        if (count($errors) === 0) {
            return null;
        }

        $details = [];
        foreach ($errors as $error) {
            $details[$error->getPropertyPath()] = $error->getMessage();
        }

        return ['result' => 'validation_error', 'errors' => $details];
    }

    public function delete(string $id): bool
    {
        $instance = $this->radarrRepository->find($id);
        if (!$instance instanceof RadarrInstance) {
            return false;
        }

        $this->em->remove($instance);
        $this->em->flush();

        return true;
    }

    /**
     * @return array{success: bool, error?: string}
     */
    public function testConnection(string $id): ?array
    {
        $instance = $this->radarrRepository->find($id);
        if (!$instance instanceof RadarrInstance) {
            return null;
        }

        return $this->radarrService->testConnection($instance);
    }

    /**
     * @return array{result: string, data?: array<int, mixed>, error?: string}|null
     */
    public function rootFolders(string $id): ?array
    {
        $instance = $this->radarrRepository->find($id);
        if (!$instance instanceof RadarrInstance) {
            return null;
        }

        try {
            $folders = $this->radarrService->getRootFolders($instance);
            $instance->setRootFolders($folders);
            $this->em->flush();

            return ['result' => 'ok', 'data' => $folders];
        } catch (Exception $e) {
            return ['result' => 'error', 'error' => $e->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    private function serialize(RadarrInstance $instance): array
    {
        return [
            'id' => (string)$instance->getId(),
            'name' => $instance->getName(),
            'url' => $instance->getUrl(),
            'api_key' => $instance->getApiKey() ? ('••••' . substr($instance->getApiKey(), -4)) : null,
            'is_active' => $instance->isActive(),
            'root_folders' => $instance->getRootFolders(),
            'last_sync_at' => $instance->getLastSyncAt()?->format('c'),
            'created_at' => $instance->getCreatedAt()->format('c'),
            'updated_at' => $instance->getUpdatedAt()->format('c'),
        ];
    }
}
