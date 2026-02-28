<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\MediaPlayerConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/media-players')]
#[IsGranted('ROLE_ADMIN')]
class MediaPlayerController extends AbstractController
{
    public function __construct(private readonly MediaPlayerConfigService $mediaPlayerConfigService)
    {
    }

    #[Route('', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $data = $this->mediaPlayerConfigService->list();

        return $this->json(['data' => $data, 'meta' => ['total' => count($data)]]);
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return $this->json(['error' => ['code' => 400, 'message' => 'Invalid JSON']], Response::HTTP_BAD_REQUEST);
        }

        $result = $this->mediaPlayerConfigService->create($data);
        if ($result['result'] === 'validation_error') {
            return $this->json(['error' => ['code' => 422, 'message' => 'Validation failed', 'details' => $result['errors']]], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['data' => $result['data']], Response::HTTP_CREATED);
    }

    #[Route('/{id}', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return $this->json(['error' => ['code' => 400, 'message' => 'Invalid JSON']], Response::HTTP_BAD_REQUEST);
        }

        $result = $this->mediaPlayerConfigService->update($id, $data);
        if ($result === null) {
            return $this->json(['error' => ['code' => 404, 'message' => 'Media player not found']], Response::HTTP_NOT_FOUND);
        }
        if ($result['result'] === 'validation_error') {
            return $this->json(['error' => ['code' => 422, 'message' => 'Validation failed', 'details' => $result['errors']]], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['data' => $result['data']]);
    }

    #[Route('/{id}', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        if (!$this->mediaPlayerConfigService->delete($id)) {
            return $this->json(['error' => ['code' => 404, 'message' => 'Media player not found']], Response::HTTP_NOT_FOUND);
        }

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/test', methods: ['POST'])]
    public function testConnection(string $id): JsonResponse
    {
        $result = $this->mediaPlayerConfigService->testConnection($id);
        if ($result === null) {
            return $this->json(['error' => ['code' => 404, 'message' => 'Media player not found']], Response::HTTP_NOT_FOUND);
        }
        if ($result['success']) {
            return $this->json(['data' => $result]);
        }

        return $this->json(['error' => ['code' => 400, 'message' => sprintf('Connection failed: %s', $result['error'] ?? 'Unknown error')]], Response::HTTP_BAD_REQUEST);
    }
}
