<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\RefreshToken;
use App\Entity\Setting;
use App\Entity\User;
use App\Repository\SettingRepository;
use App\Repository\UserRepository;
use DateTime;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class AuthService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly SettingRepository $settingRepository,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly ValidatorInterface $validator,
    ) {}

    public function isSetupCompleted(): bool
    {
        $setting = $this->settingRepository->findOneBy(['settingKey' => 'setup_completed']);

        return $setting !== null && $setting->getSettingValue() === 'true';
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{result: string, data?: array<string, mixed>, error?: string, details?: array<string, string>}
     */
    public function setup(array $data): array
    {
        if ($this->isSetupCompleted()) {
            return ['result' => 'already_completed'];
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

            return ['result' => 'validation_error', 'details' => $details];
        }

        $password = $data['password'] ?? '';
        if (strlen((string) $password) < 8) {
            return ['result' => 'validation_error', 'details' => ['password' => 'Password must be at least 8 characters']];
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, (string) $password));
        $this->em->persist($user);

        $setupCompleted = $this->settingRepository->findOneBy(['settingKey' => 'setup_completed']);
        $setting = $setupCompleted ?? new Setting();
        $setting->setSettingKey('setup_completed');
        $setting->setSettingValue('true');
        $setting->setSettingType('boolean');
        $this->em->persist($setting);

        $this->createDefaultSettings();
        $this->em->flush();

        return [
            'result' => 'created',
            'data' => [
                'id' => (string) $user->getId(),
                'email' => $user->getEmail(),
                'username' => $user->getUsername(),
                'role' => $user->getRole(),
            ],
        ];
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int, user: array<string, mixed>}|null
     */
    public function login(string $email, string $password): ?array
    {
        $user = $this->userRepository->findOneBy(['email' => $email]);
        if (!$user || !$this->passwordHasher->isPasswordValid($user, $password)) {
            return null;
        }
        if (!$user->isActive()) {
            return ['error' => 'disabled'];
        }

        $user->setLastLoginAt(new DateTimeImmutable());
        $this->em->flush();

        $accessToken = $this->jwtManager->create($user);

        $refreshToken = new RefreshToken();
        $refreshToken->setUsername($user->getUserIdentifier());
        $refreshToken->setRefreshToken(bin2hex(random_bytes(64)));
        $refreshToken->setValid(new DateTime('+30 days'));
        $this->em->persist($refreshToken);
        $this->em->flush();

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken->getRefreshToken(),
            'user' => [
                'id' => (string) $user->getId(),
                'username' => $user->getUsername(),
                'role' => $user->getRole(),
            ],
        ];
    }

    /**
     * @return array{access_token: string, refresh_token: string}|null
     */
    public function refresh(string $refreshTokenString): ?array
    {
        $refreshTokenRepo = $this->em->getRepository(RefreshToken::class);
        $refreshToken = $refreshTokenRepo->findOneBy(['refreshToken' => $refreshTokenString]);

        if (!$refreshToken || $refreshToken->getValid() < new DateTime()) {
            return null;
        }

        $user = $this->userRepository->findOneBy(['email' => $refreshToken->getUsername()]);
        if (!$user instanceof User) {
            return null;
        }

        $accessToken = $this->jwtManager->create($user);

        $newRefreshToken = bin2hex(random_bytes(64));
        $refreshToken->setRefreshToken($newRefreshToken);
        $refreshToken->setValid(new DateTime('+30 days'));
        $this->em->flush();

        return ['access_token' => $accessToken, 'refresh_token' => $newRefreshToken];
    }

    /** @return array<string, mixed> */
    public function me(User $user): array
    {
        return [
            'id' => (string) $user->getId(),
            'email' => $user->getEmail(),
            'username' => $user->getUsername(),
            'role' => $user->getRole(),
            'is_active' => $user->isActive(),
            'created_at' => $user->getCreatedAt()->format('c'),
            'last_login_at' => $user->getLastLoginAt()?->format('c'),
        ];
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
            if (!$existing instanceof Setting) {
                $setting = new Setting();
                $setting->setSettingKey($key);
                $setting->setSettingValue($value);
                $setting->setSettingType($type);
                $this->em->persist($setting);
            }
        }
    }
}
