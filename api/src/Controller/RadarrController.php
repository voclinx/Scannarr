<?php

namespace App\Controller;

use App\Entity\RadarrInstance;
use App\Repository\RadarrInstanceRepository;
use App\Service\RadarrService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/v1/radarr-instances')]
#[IsGranted('ROLE_ADMIN')]
class RadarrController extends AbstractController
{
    public function __construct(
        private RadarrInstanceRepository $radarrRepository,
        private EntityManagerInterface $em,
        private ValidatorInterface $validator,
        private RadarrService $radarrService,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $instances = $this->radarrRepository->findAll();

        $data = array_map(fn(RadarrInstance $i) => $this->serialize($i), $instances);

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

        $instance = new RadarrInstance();
        $instance->setName($payload['name'] ?? '');
        $instance->setUrl($payload['url'] ?? '');
        $instance->setApiKey($payload['api_key'] ?? '');

        if (isset($payload['is_active'])) {
            $instance->setIsActive((bool) $payload['is_active']);
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
        $instance = $this->radarrRepository->find($id);

        if (!$instance) {
            return $this->json(['error' => ['code' => 404, 'message' => 'Radarr instance not found']], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode($request->getContent(), true);

        if (!$payload) {
            return $this->json(['error' => ['code' => 400, 'message' => 'Invalid JSON']], Response::HTTP_BAD_REQUEST);
        }

        if (isset($payload['name'])) {
            $instance->setName($payload['name']);
        }
        if (isset($payload['url'])) {
            $instance->setUrl($payload['url']);
        }
        if (!empty($payload['api_key']) && is_string($payload['api_key']) && !str_starts_with($payload['api_key'], '••••')) {
            $instance->setApiKey($payload['api_key']);
        }
        if (isset($payload['is_active'])) {
            $instance->setIsActive((bool) $payload['is_active']);
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
        $instance = $this->radarrRepository->find($id);

        if (!$instance) {
            return $this->json(['error' => ['code' => 404, 'message' => 'Radarr instance not found']], Response::HTTP_NOT_FOUND);
        }

        $this->em->remove($instance);
        $this->em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/test', methods: ['POST'])]
    public function testConnection(string $id): JsonResponse
    {
        $instance = $this->radarrRepository->find($id);

        if (!$instance) {
            return $this->json(['error' => ['code' => 404, 'message' => 'Radarr instance not found']], Response::HTTP_NOT_FOUND);
        }

        $result = $this->radarrService->testConnection($instance);

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

    #[Route('/{id}/root-folders', methods: ['GET'])]
    public function rootFolders(string $id): JsonResponse
    {
        $instance = $this->radarrRepository->find($id);

        if (!$instance) {
            return $this->json(['error' => ['code' => 404, 'message' => 'Radarr instance not found']], Response::HTTP_NOT_FOUND);
        }

        try {
            $folders = $this->radarrService->getRootFolders($instance);

            // Update cached root folders
            $instance->setRootFolders($folders);
            $this->em->flush();

            return $this->json(['data' => $folders]);
        } catch (\Exception $e) {
            return $this->json([
                'error' => ['code' => 400, 'message' => sprintf('Failed to get root folders: %s', $e->getMessage())],
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Serialize a RadarrInstance to array.
     *
     * @return array<string, mixed>
     */
    private function serialize(RadarrInstance $instance): array
    {
        return [
            'id' => (string) $instance->getId(),
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
