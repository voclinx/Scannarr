<?php

namespace App\Controller;

use App\Entity\Setting;
use App\Entity\User;
use App\Repository\SettingRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/v1/auth')]
class AuthController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $passwordHasher,
        private JWTTokenManagerInterface $jwtManager,
        private ValidatorInterface $validator,
        private SettingRepository $settingRepository,
        private UserRepository $userRepository,
    ) {}

    #[Route('/setup', methods: ['POST'])]
    public function setup(Request $request): JsonResponse
    {
        $setupCompleted = $this->settingRepository->findOneBy(['settingKey' => 'setup_completed']);
        if ($setupCompleted && $setupCompleted->getSettingValue() === 'true') {
            return $this->json([
                'error' => [
                    'code' => 403,
                    'message' => 'Setup already completed',
                ],
            ], 403);
        }

        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return $this->json([
                'error' => ['code' => 400, 'message' => 'Invalid JSON'],
            ], 400);
        }

        $user = new User();
        $user->setEmail($data['email'] ?? '');
        $user->setUsername($data['username'] ?? '');
        $user->setRole('ROLE_ADMIN');

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
        if (strlen($password) < 8) {
            return $this->json([
                'error' => ['code' => 422, 'message' => 'Validation failed', 'details' => ['password' => 'Password must be at least 8 characters']],
            ], 422);
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $this->em->persist($user);

        // Mark setup as completed
        $setting = $setupCompleted ?? new Setting();
        $setting->setSettingKey('setup_completed');
        $setting->setSettingValue('true');
        $setting->setSettingType('boolean');
        $this->em->persist($setting);

        // Insert default settings
        $this->createDefaultSettings();

        $this->em->flush();

        return $this->json([
            'data' => [
                'id' => (string) $user->getId(),
                'email' => $user->getEmail(),
                'username' => $user->getUsername(),
                'role' => $user->getRole(),
            ],
        ], 201);
    }

    #[Route('/login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return $this->json([
                'error' => ['code' => 400, 'message' => 'Invalid JSON'],
            ], 400);
        }

        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';

        $user = $this->userRepository->findOneBy(['email' => $email]);
        if (!$user || !$this->passwordHasher->isPasswordValid($user, $password)) {
            return $this->json([
                'error' => ['code' => 401, 'message' => 'Invalid credentials'],
            ], 401);
        }

        if (!$user->isActive()) {
            return $this->json([
                'error' => ['code' => 401, 'message' => 'Account is disabled'],
            ], 401);
        }

        // Update last login
        $user->setLastLoginAt(new \DateTimeImmutable());
        $this->em->flush();

        $accessToken = $this->jwtManager->create($user);

        // Create refresh token
        $refreshTokenManager = $this->em->getRepository(\Gesdinet\JWTRefreshTokenBundle\Entity\RefreshToken::class);
        $refreshToken = new \Gesdinet\JWTRefreshTokenBundle\Entity\RefreshToken();
        $refreshToken->setUsername($user->getUserIdentifier());
        $refreshToken->setRefreshToken(bin2hex(random_bytes(64)));
        $refreshToken->setValid(new \DateTime('+30 days'));
        $this->em->persist($refreshToken);
        $this->em->flush();

        return $this->json([
            'data' => [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken->getRefreshToken(),
                'expires_in' => (int) $this->getParameter('lexik_jwt_authentication.token_ttl'),
                'user' => [
                    'id' => (string) $user->getId(),
                    'username' => $user->getUsername(),
                    'role' => $user->getRole(),
                ],
            ],
        ]);
    }

    #[Route('/refresh', methods: ['POST'])]
    public function refresh(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $refreshTokenString = $data['refresh_token'] ?? '';

        if (!$refreshTokenString) {
            return $this->json([
                'error' => ['code' => 400, 'message' => 'Missing refresh_token'],
            ], 400);
        }

        $refreshTokenRepo = $this->em->getRepository(\Gesdinet\JWTRefreshTokenBundle\Entity\RefreshToken::class);
        $refreshToken = $refreshTokenRepo->findOneBy(['refreshToken' => $refreshTokenString]);

        if (!$refreshToken || $refreshToken->getValid() < new \DateTime()) {
            return $this->json([
                'error' => ['code' => 401, 'message' => 'Invalid or expired refresh token'],
            ], 401);
        }

        $user = $this->userRepository->findOneBy(['email' => $refreshToken->getUsername()]);
        if (!$user) {
            return $this->json([
                'error' => ['code' => 401, 'message' => 'User not found'],
            ], 401);
        }

        $accessToken = $this->jwtManager->create($user);

        // Rotate refresh token
        $newRefreshToken = bin2hex(random_bytes(64));
        $refreshToken->setRefreshToken($newRefreshToken);
        $refreshToken->setValid(new \DateTime('+30 days'));
        $this->em->flush();

        return $this->json([
            'data' => [
                'access_token' => $accessToken,
                'refresh_token' => $newRefreshToken,
                'expires_in' => (int) $this->getParameter('lexik_jwt_authentication.token_ttl'),
            ],
        ]);
    }

    #[Route('/me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json([
            'data' => [
                'id' => (string) $user->getId(),
                'email' => $user->getEmail(),
                'username' => $user->getUsername(),
                'role' => $user->getRole(),
                'is_active' => $user->isActive(),
                'created_at' => $user->getCreatedAt()->format('c'),
                'last_login_at' => $user->getLastLoginAt()?->format('c'),
            ],
        ]);
    }

    private function createDefaultSettings(): void
    {
        $defaults = [
            ['discord_webhook_url', null, 'string'],
            ['discord_reminder_days', '3', 'integer'],
            ['qbittorrent_url', null, 'string'],
            ['qbittorrent_username', null, 'string'],
            ['qbittorrent_password', null, 'string'],
        ];

        foreach ($defaults as [$key, $value, $type]) {
            $existing = $this->settingRepository->findOneBy(['settingKey' => $key]);
            if (!$existing) {
                $setting = new Setting();
                $setting->setSettingKey($key);
                $setting->setSettingValue($value);
                $setting->setSettingType($type);
                $this->em->persist($setting);
            }
        }
    }
}
