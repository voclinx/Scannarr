<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\TrackerRuleService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/tracker-rules')]
class TrackerRuleController extends AbstractController
{
    public function __construct(private readonly TrackerRuleService $trackerRuleService)
    {
    }

    #[Route('', methods: ['GET'])]
    #[IsGranted('ROLE_ADVANCED_USER')]
    public function list(): JsonResponse
    {
        return $this->json(['data' => $this->trackerRuleService->list()]);
    }

    #[Route('/{id}', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function update(string $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return $this->json(['error' => ['code' => 400, 'message' => 'Invalid JSON']], Response::HTTP_BAD_REQUEST);
        }

        $result = $this->trackerRuleService->update($id, $data);
        if ($result === null) {
            return $this->json(['error' => ['code' => 404, 'message' => 'Tracker rule not found']], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['data' => $result]);
    }
}
