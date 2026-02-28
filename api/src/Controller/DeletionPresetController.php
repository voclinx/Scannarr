<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\DeletionPresetService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/deletion-presets')]
class DeletionPresetController extends AbstractController
{
    public function __construct(private readonly DeletionPresetService $presetService)
    {
    }

    #[Route('', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function list(): JsonResponse
    {
        return $this->json(['data' => $this->presetService->list()]);
    }

    #[Route('', methods: ['POST'])]
    #[IsGranted('ROLE_ADVANCED_USER')]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return $this->json(['error' => ['code' => 400, 'message' => 'Invalid JSON']], Response::HTTP_BAD_REQUEST);
        }

        /** @var User $user */
        $user = $this->getUser();
        $result = $this->presetService->create($data, $user);

        if ($result['result'] === 'validation_error') {
            return $this->json(
                ['error' => ['code' => 422, 'message' => 'Validation failed', 'details' => [$result['field'] => $result['message']]]],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $this->json(['data' => $result['data']], Response::HTTP_CREATED);
    }

    #[Route('/{id}', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function detail(string $id): JsonResponse
    {
        $data = $this->presetService->find($id);
        if ($data === null) {
            return $this->json(['error' => ['code' => 404, 'message' => 'Preset not found']], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['data' => $data]);
    }

    #[Route('/{id}', methods: ['PUT'])]
    #[IsGranted('ROLE_ADVANCED_USER')]
    public function update(string $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return $this->json(['error' => ['code' => 400, 'message' => 'Invalid JSON']], Response::HTTP_BAD_REQUEST);
        }

        $result = $this->presetService->update($id, $data);

        return match ($result['result']) {
            'not_found' => $this->json(['error' => ['code' => 404, 'message' => 'Preset not found']], Response::HTTP_NOT_FOUND),
            'system_preset' => $this->json(['error' => ['code' => 403, 'message' => 'Cannot modify a system preset']], Response::HTTP_FORBIDDEN),
            default => $this->json(['data' => $result['data']]),
        };
    }

    #[Route('/{id}', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADVANCED_USER')]
    public function delete(string $id): JsonResponse
    {
        $result = $this->presetService->delete($id);

        return match ($result) {
            'not_found' => $this->json(['error' => ['code' => 404, 'message' => 'Preset not found']], Response::HTTP_NOT_FOUND),
            'system_preset' => $this->json(['error' => ['code' => 403, 'message' => 'Cannot delete a system preset']], Response::HTTP_FORBIDDEN),
            default => $this->json(null, Response::HTTP_NO_CONTENT),
        };
    }
}
