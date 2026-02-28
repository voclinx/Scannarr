<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\AuthService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/auth')]
class AuthController extends AbstractController
{
    public function __construct(private readonly AuthService $authService) {}

    #[Route('/setup-status', methods: ['GET'])]
    public function setupStatus(): JsonResponse
    {
        return $this->json(['data' => ['setup_completed' => $this->authService->isSetupCompleted()]]);
    }

    #[Route('/setup', methods: ['POST'])]
    public function setup(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return $this->json(['error' => ['code' => 400, 'message' => 'Invalid JSON']], 400);
        }

        $result = $this->authService->setup($data);

        if ($result['result'] === 'already_completed') {
            return $this->json(['error' => ['code' => 403, 'message' => 'Setup already completed']], 403);
        }
        if ($result['result'] === 'validation_error') {
            return $this->json(['error' => ['code' => 422, 'message' => 'Validation failed', 'details' => $result['details']]], 422);
        }

        return $this->json(['data' => $result['data']], 201);
    }

    #[Route('/login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return $this->json(['error' => ['code' => 400, 'message' => 'Invalid JSON']], 400);
        }

        $result = $this->authService->login($data['email'] ?? '', $data['password'] ?? '');

        if ($result === null) {
            return $this->json(['error' => ['code' => 401, 'message' => 'Invalid credentials']], 401);
        }
        if (isset($result['error']) && $result['error'] === 'disabled') {
            return $this->json(['error' => ['code' => 401, 'message' => 'Account is disabled']], 401);
        }

        return $this->json(['data' => array_merge($result, ['expires_in' => (int) $this->getParameter('lexik_jwt_authentication.token_ttl')])]);
    }

    #[Route('/refresh', methods: ['POST'])]
    public function refresh(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $refreshTokenString = $data['refresh_token'] ?? '';

        if (!$refreshTokenString) {
            return $this->json(['error' => ['code' => 400, 'message' => 'Missing refresh_token']], 400);
        }

        $result = $this->authService->refresh((string) $refreshTokenString);
        if ($result === null) {
            return $this->json(['error' => ['code' => 401, 'message' => 'Invalid or expired refresh token']], 401);
        }

        return $this->json(['data' => array_merge($result, ['expires_in' => (int) $this->getParameter('lexik_jwt_authentication.token_ttl')])]);
    }

    #[Route('/me', methods: ['GET'])]
    #[IsGranted('ROLE_GUEST')]
    public function me(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json(['data' => $this->authService->me($user)]);
    }
}
