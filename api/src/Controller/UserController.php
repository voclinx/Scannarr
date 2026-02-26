<?php

namespace App\Controller;

use App\Entity\RefreshToken;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/v1/users')]
class UserController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidatorInterface $validator,
        private readonly JWTTokenManagerInterface $jwtManager,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $page = max(1, (int)$request->query->get('page', 1));
        $limit = min(100, max(1, (int)$request->query->get('limit', 25)));
        $offset = ($page - 1) * $limit;

        $total = $this->userRepository->count([]);
        $users = $this->userRepository->findBy([], ['createdAt' => 'DESC'], $limit, $offset);

        $data = array_map($this->serializeUser(...), $users);

        return $this->json([
            'data' => $data,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return $this->json([
                'error' => ['code' => 400, 'message' => 'Invalid JSON'],
            ], 400);
        }

        $user = new User();
        $user->setEmail($data['email'] ?? '');
        $user->setUsername($data['username'] ?? '');
        $user->setRole($data['role'] ?? 'ROLE_USER');

        $errors = $this->validator->validate($user);
        if (count($errors) > 0) {
            $details = [];
            foreach ($errors as $error) {
                $details[$error->getPropertyPath()] = $error->getMessage();
            }

            return $this->json([
                'error' => ['code' => 422, 'message' => 'Validation failed', 'details' => $details],
            ], 422);
        }

        $password = $data['password'] ?? '';
        if (strlen((string)$password) < 8) {
            return $this->json([
                'error' => ['code' => 422, 'message' => 'Validation failed', 'details' => ['password' => 'Password must be at least 8 characters']],
            ], 422);
        }

        $validRoles = ['ROLE_ADMIN', 'ROLE_ADVANCED_USER', 'ROLE_USER', 'ROLE_GUEST'];
        if (!in_array($user->getRole(), $validRoles, true)) {
            return $this->json([
                'error' => ['code' => 422, 'message' => 'Validation failed', 'details' => ['role' => 'Invalid role']],
            ], 422);
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $this->em->persist($user);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            return $this->json([
                'error' => ['code' => 409, 'message' => 'A user with this email or username already exists'],
            ], 409);
        }

        return $this->json([
            'data' => $this->serializeUser($user),
        ], 201);
    }

    #[Route('/{id}', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $user = $this->userRepository->find($id);
        if (!$user instanceof User) {
            return $this->json([
                'error' => ['code' => 404, 'message' => 'User not found'],
            ], 404);
        }

        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return $this->json([
                'error' => ['code' => 400, 'message' => 'Invalid JSON'],
            ], 400);
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $isSelfEdit = $user->getId()->equals($currentUser->getId());
        $oldEmail = $user->getEmail();

        if (isset($data['email'])) {
            $user->setEmail($data['email']);
        }
        if (isset($data['username'])) {
            $user->setUsername($data['username']);
        }
        if (isset($data['role'])) {
            $validRoles = ['ROLE_ADMIN', 'ROLE_ADVANCED_USER', 'ROLE_USER', 'ROLE_GUEST'];
            if (!in_array($data['role'], $validRoles, true)) {
                return $this->json([
                    'error' => ['code' => 422, 'message' => 'Validation failed', 'details' => ['role' => 'Invalid role']],
                ], 422);
            }
            $user->setRole($data['role']);
        }
        if (isset($data['is_active'])) {
            $user->setIsActive((bool)$data['is_active']);
        }
        if (isset($data['password'])) {
            if (strlen((string)$data['password']) < 8) {
                return $this->json([
                    'error' => ['code' => 422, 'message' => 'Validation failed', 'details' => ['password' => 'Password must be at least 8 characters']],
                ], 422);
            }
            $user->setPassword($this->passwordHasher->hashPassword($user, $data['password']));
        }

        $errors = $this->validator->validate($user);
        if (count($errors) > 0) {
            $details = [];
            foreach ($errors as $error) {
                $details[$error->getPropertyPath()] = $error->getMessage();
            }

            return $this->json([
                'error' => ['code' => 422, 'message' => 'Validation failed', 'details' => $details],
            ], 422);
        }

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            return $this->json([
                'error' => ['code' => 409, 'message' => 'A user with this email or username already exists'],
            ], 409);
        }

        $response = ['data' => $this->serializeUser($user)];

        // If the user changed their own email, the JWT (signed with old email) becomes invalid.
        // Regenerate tokens and update refresh tokens in DB.
        $emailChanged = $isSelfEdit && $oldEmail !== $user->getEmail();
        if ($emailChanged) {
            $newAccessToken = $this->jwtManager->create($user);

            // Update existing refresh tokens to use the new email
            $refreshTokenRepo = $this->em->getRepository(RefreshToken::class);
            $oldRefreshTokens = $refreshTokenRepo->findBy(['username' => $oldEmail]);
            foreach ($oldRefreshTokens as $rt) {
                $rt->setUsername($user->getUserIdentifier());
            }
            $this->em->flush();

            $response['data']['new_tokens'] = [
                'access_token' => $newAccessToken,
            ];
        }

        return $this->json($response);
    }

    #[Route('/{id}', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $user = $this->userRepository->find($id);
        if (!$user instanceof User) {
            return $this->json([
                'error' => ['code' => 404, 'message' => 'User not found'],
            ], 404);
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();
        if ($user->getId()->equals($currentUser->getId())) {
            return $this->json([
                'error' => ['code' => 400, 'message' => 'Cannot delete your own account'],
            ], 400);
        }

        $this->em->remove($user);
        $this->em->flush();

        return $this->json(null, 204);
    }

    private function serializeUser(User $user): array
    {
        return [
            'id' => (string)$user->getId(),
            'email' => $user->getEmail(),
            'username' => $user->getUsername(),
            'role' => $user->getRole(),
            'is_active' => $user->isActive(),
            'created_at' => $user->getCreatedAt()->format('c'),
            'last_login_at' => $user->getLastLoginAt()?->format('c'),
        ];
    }
}
