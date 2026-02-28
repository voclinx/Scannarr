<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\Request\DeleteMovieRequest;
use App\Entity\User;
use App\Service\MovieService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/movies')]
class MovieController extends AbstractController
{
    public function __construct(private readonly MovieService $movieService) {}

    #[Route('', methods: ['GET'])]
    #[IsGranted('ROLE_GUEST')]
    public function list(Request $request): JsonResponse
    {
        $result = $this->movieService->list($request->query->all());

        return $this->json(['data' => $result['data'], 'meta' => $result['meta']]);
    }

    #[Route('/{id}', methods: ['GET'])]
    #[IsGranted('ROLE_GUEST')]
    public function detail(string $id): JsonResponse
    {
        $data = $this->movieService->detail($id);
        if ($data === null) {
            return $this->json(['error' => ['code' => 404, 'message' => 'Movie not found']], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['data' => $data]);
    }

    #[Route('/{id}', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADVANCED_USER')]
    public function delete(string $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $dto = DeleteMovieRequest::fromRequest($request);
        $result = $this->movieService->delete($id, $dto, $user);

        if ($result->status === 'not_found') {
            return $this->json(['error' => ['code' => 404, 'message' => 'Movie not found']], Response::HTTP_NOT_FOUND);
        }
        if ($result->status === 'invalid_files') {
            $error = ['code' => 400, 'message' => $result->message ?? 'Invalid file IDs', 'invalid_ids' => $result->invalidIds ?? []];

            return $this->json(['error' => $error], Response::HTTP_BAD_REQUEST);
        }
        if ($result->status === 'invalid_replacement_map') {
            return $this->json(['error' => ['code' => 400, 'message' => $result->message ?? 'Invalid replacement map']], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['data' => $result->toArray()], $result->httpStatus());
    }

    #[Route('/{id}/protect', methods: ['PUT'], priority: 10)]
    #[IsGranted('ROLE_ADVANCED_USER')]
    public function protect(string $id, Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true) ?? [];
        $data = $this->movieService->protect($id, (bool) ($payload['is_protected'] ?? false));

        if ($data === null) {
            return $this->json(['error' => ['code' => 404, 'message' => 'Movie not found']], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['data' => $data]);
    }

    #[Route('/sync', methods: ['POST'], priority: 10)]
    #[IsGranted('ROLE_ADMIN')]
    public function sync(): JsonResponse
    {
        $this->movieService->sync();

        return $this->json(['data' => ['message' => 'Radarr sync started. Movies will be imported in the background.']], Response::HTTP_ACCEPTED);
    }
}
