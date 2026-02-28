<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\MediaPlayerInstance;
use App\ExternalService\MediaPlayer\JellyfinService;
use App\ExternalService\MediaPlayer\PlexService;
use App\Repository\MediaPlayerInstanceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class MediaPlayerConfigService
{
    public function __construct(
        private readonly MediaPlayerInstanceRepository $playerRepository,
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
        private readonly PlexService $plexService,
        private readonly JellyfinService $jellyfinService,
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function list(): array
    {
        $instances = $this->playerRepository->findAll();

        return array_map($this->serialize(...), $instances);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{result: string, data?: array<string, mixed>, errors?: array<string, string>}
     */
    public function create(array $data): array
    {
        $instance = new MediaPlayerInstance();
        $instance->setName($data['name'] ?? '');
        $instance->setType($data['type'] ?? '');
        $instance->setUrl($data['url'] ?? '');
        $instance->setToken($data['token'] ?? '');
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
        $instance = $this->playerRepository->find($id);
        if (!$instance instanceof MediaPlayerInstance) {
            return null;
        }

        if (isset($data['name'])) {
            $instance->setName($data['name']);
        }
        if (isset($data['type'])) {
            $instance->setType($data['type']);
        }
        if (isset($data['url'])) {
            $instance->setUrl($data['url']);
        }
        if (!empty($data['token']) && is_string($data['token']) && !str_starts_with($data['token'], '••••')) {
            $instance->setToken($data['token']);
        }
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

        $this->em->flush();

        return ['result' => 'updated', 'data' => $this->serialize($instance)];
    }

    public function delete(string $id): bool
    {
        $instance = $this->playerRepository->find($id);
        if (!$instance instanceof MediaPlayerInstance) {
            return false;
        }

        $this->em->remove($instance);
        $this->em->flush();

        return true;
    }

    /**
     * @return array{success: bool, error?: string}|null
     */
    public function testConnection(string $id): ?array
    {
        $instance = $this->playerRepository->find($id);
        if (!$instance instanceof MediaPlayerInstance) {
            return null;
        }

        return match ($instance->getType()) {
            'plex' => $this->plexService->testConnection($instance),
            'jellyfin' => $this->jellyfinService->testConnection($instance),
            default => ['success' => false, 'error' => 'Unknown player type: ' . $instance->getType()],
        };
    }

    /** @return array<string, mixed> */
    private function serialize(MediaPlayerInstance $instance): array
    {
        return [
            'id' => (string)$instance->getId(),
            'name' => $instance->getName(),
            'type' => $instance->getType(),
            'url' => $instance->getUrl(),
            'token' => $instance->getToken() ? ('••••' . substr($instance->getToken(), -4)) : null,
            'is_active' => $instance->isActive(),
            'created_at' => $instance->getCreatedAt()->format('c'),
            'updated_at' => $instance->getUpdatedAt()->format('c'),
        ];
    }
}
