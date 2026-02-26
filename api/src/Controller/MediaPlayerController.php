<?php

namespace App\Controller;

use App\Entity\MediaPlayerInstance;
use App\Repository\MediaPlayerInstanceRepository;
use App\Service\JellyfinService;
use App\Service\PlexService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/v1/media-players')]
#[IsGranted('ROLE_ADMIN')]
class MediaPlayerController extends AbstractController
{
    public function __construct(
        private readonly MediaPlayerInstanceRepository $playerRepository,
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
        private readonly PlexService $plexService,
        private readonly JellyfinService $jellyfinService,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $instances = $this->playerRepository->findAll();

        $data = array_map($this->serialize(...), $instances);

        return $this->json([
            'data' => $data,
            'meta' => ['total' => count($data)],
        ]);
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        if (!$payload) {
            return $this->json(['error' => ['code' => 400, 'message' => 'Invalid JSON']], Response::HTTP_BAD_REQUEST);
        }

        $instance = new MediaPlayerInstance();
        $instance->setName($payload['name'] ?? '');
        $instance->setType($payload['type'] ?? '');
        $instance->setUrl($payload['url'] ?? '');
        $instance->setToken($payload['token'] ?? '');

        if (isset($payload['is_active'])) {
            $instance->setIsActive((bool)$payload['is_active']);
        }

        $errors = $this->validator->validate($instance);
        if (count($errors) > 0) {
            $details = [];
            foreach ($errors as $error) {
                $details[$error->getPropertyPath()] = $error->getMessage();
            }

            return $this->json([
                'error' => ['code' => 422, 'message' => 'Validation failed', 'details' => $details],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->em->persist($instance);
        $this->em->flush();

        return $this->json(['data' => $this->serialize($instance)], Response::HTTP_CREATED);
    }

    #[Route('/{id}', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $instance = $this->playerRepository->find($id);

        if (!$instance instanceof MediaPlayerInstance) {
            return $this->json(['error' => ['code' => 404, 'message' => 'Media player not found']], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode($request->getContent(), true);

        if (!$payload) {
            return $this->json(['error' => ['code' => 400, 'message' => 'Invalid JSON']], Response::HTTP_BAD_REQUEST);
        }

        if (isset($payload['name'])) {
            $instance->setName($payload['name']);
        }
        if (isset($payload['type'])) {
            $instance->setType($payload['type']);
        }
        if (isset($payload['url'])) {
            $instance->setUrl($payload['url']);
        }
        if (!empty($payload['token']) && is_string($payload['token']) && !str_starts_with($payload['token'], '••••')) {
            $instance->setToken($payload['token']);
        }
        if (isset($payload['is_active'])) {
            $instance->setIsActive((bool)$payload['is_active']);
        }

        $errors = $this->validator->validate($instance);
        if (count($errors) > 0) {
            $details = [];
            foreach ($errors as $error) {
                $details[$error->getPropertyPath()] = $error->getMessage();
            }

            return $this->json([
                'error' => ['code' => 422, 'message' => 'Validation failed', 'details' => $details],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->em->flush();

        return $this->json(['data' => $this->serialize($instance)]);
    }

    #[Route('/{id}', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $instance = $this->playerRepository->find($id);

        if (!$instance instanceof MediaPlayerInstance) {
            return $this->json(['error' => ['code' => 404, 'message' => 'Media player not found']], Response::HTTP_NOT_FOUND);
        }

        $this->em->remove($instance);
        $this->em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/test', methods: ['POST'])]
    public function testConnection(string $id): JsonResponse
    {
        $instance = $this->playerRepository->find($id);

        if (!$instance instanceof MediaPlayerInstance) {
            return $this->json(['error' => ['code' => 404, 'message' => 'Media player not found']], Response::HTTP_NOT_FOUND);
        }

        $result = match ($instance->getType()) {
            'plex' => $this->plexService->testConnection($instance),
            'jellyfin' => $this->jellyfinService->testConnection($instance),
            default => ['success' => false, 'error' => 'Unknown player type: ' . $instance->getType()],
        };

        if ($result['success']) {
            return $this->json(['data' => $result]);
        }

        return $this->json([
            'error' => [
                'code' => 400,
                'message' => sprintf('Connection failed: %s', $result['error'] ?? 'Unknown error'),
            ],
        ], Response::HTTP_BAD_REQUEST);
    }

    /**
     * @return array<string, mixed>
     */
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
