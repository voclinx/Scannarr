<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\RefreshToken;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class UserService
{
    public function __construct(
        private UserRepository $userRepository,
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $passwordHasher,
        private ValidatorInterface $validator,
        private JWTTokenManagerInterface $jwtManager,
    ) {
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function list(int $page, int $limit): array
    {
        $offset = ($page - 1) * $limit;
        $total = $this->userRepository->count([]);
        $users = $this->userRepository->findBy([], ['createdAt' => 'DESC'], $limit, $offset);

        return [
            'data' => array_map($this->serialize(...), $users),
            'meta' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{result: string, data?: array<string, mixed>, error?: string}
     */
    public function create(array $data): array
    {
        $user = new User();
        $user->setEmail($data['email'] ?? '');
        $user->setUsername($data['username'] ?? '');
        $user->setRole($data['role'] ?? 'ROLE_USER');

        $validationError = $this->validateCreateData($user, $data);
        if ($validationError !== null) {
            return $validationError;
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, (string)($data['password'] ?? '')));
        $this->em->persist($user);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            return ['result' => 'duplicate'];
        }

        return ['result' => 'created', 'data' => $this->serialize($user)];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>|null Returns error array or null if valid
     */
    private function validateCreateData(User $user, array $data): ?array
    {
        $errors = $this->validator->validate($user);
        if (count($errors) > 0) {
            $details = [];
            foreach ($errors as $error) {
                $details[$error->getPropertyPath()] = $error->getMessage();
            }

            return ['result' => 'validation_error', 'details' => $details];
        }

        $password = $data['password'] ?? '';
        if (strlen((string)$password) < 8) {
            return ['result' => 'validation_error', 'details' => ['password' => 'Password must be at least 8 characters']];
        }

        $validRoles = ['ROLE_ADMIN', 'ROLE_ADVANCED_USER', 'ROLE_USER', 'ROLE_GUEST'];
        if (!in_array($user->getRole(), $validRoles, true)) {
            return ['result' => 'validation_error', 'details' => ['role' => 'Invalid role']];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{result: string, data?: array<string, mixed>, details?: array<string, string>}
     */
    public function update(string $id, array $data, User $currentUser): array
    {
        $user = $this->userRepository->find($id);
        if (!$user instanceof User) {
            return ['result' => 'not_found'];
        }

        $oldEmail = $user->getEmail();
        $isSelfEdit = $user->getId()->equals($currentUser->getId());

        $applyError = $this->applyUserUpdates($user, $data);
        if ($applyError !== null) {
            return $applyError;
        }

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            return ['result' => 'duplicate'];
        }

        $result = ['result' => 'updated', 'data' => $this->serialize($user)];
        $this->handleEmailChange($user, $oldEmail, $isSelfEdit, $result);

        return $result;
    }

    /**
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>|null Error array or null if valid
     */
    private function applyUserUpdates(User $user, array $data): ?array
    {
        if (isset($data['email'])) {
            $user->setEmail($data['email']);
        }
        if (isset($data['username'])) {
            $user->setUsername($data['username']);
        }
        if (isset($data['role'])) {
            $validRoles = ['ROLE_ADMIN', 'ROLE_ADVANCED_USER', 'ROLE_USER', 'ROLE_GUEST'];
            if (!in_array($data['role'], $validRoles, true)) {
                return ['result' => 'validation_error', 'details' => ['role' => 'Invalid role']];
            }
            $user->setRole($data['role']);
        }
        if (isset($data['is_active'])) {
            $user->setIsActive((bool)$data['is_active']);
        }
        if (isset($data['password'])) {
            if (strlen((string)$data['password']) < 8) {
                return ['result' => 'validation_error', 'details' => ['password' => 'Password must be at least 8 characters']];
            }
            $user->setPassword($this->passwordHasher->hashPassword($user, (string)$data['password']));
        }

        $errors = $this->validator->validate($user);
        if (count($errors) > 0) {
            $details = [];
            foreach ($errors as $error) {
                $details[$error->getPropertyPath()] = $error->getMessage();
            }

            return ['result' => 'validation_error', 'details' => $details];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function handleEmailChange(User $user, string $oldEmail, bool $isSelfEdit, array &$result): void
    {
        if (!$isSelfEdit || $oldEmail === $user->getEmail()) {
            return;
        }

        $newAccessToken = $this->jwtManager->create($user);
        $refreshTokenRepo = $this->em->getRepository(RefreshToken::class);
        $oldRefreshTokens = $refreshTokenRepo->findBy(['username' => $oldEmail]);
        foreach ($oldRefreshTokens as $rt) {
            $rt->setUsername($user->getUserIdentifier());
        }
        $this->em->flush();
        $result['data']['new_tokens'] = ['access_token' => $newAccessToken];
    }

    public function delete(string $id, User $currentUser): string
    {
        $user = $this->userRepository->find($id);
        if (!$user instanceof User) {
            return 'not_found';
        }
        if ($user->getId()->equals($currentUser->getId())) {
            return 'self';
        }

        $this->em->remove($user);
        $this->em->flush();

        return 'deleted';
    }

    /** @return array<string, mixed> */
    private function serialize(User $user): array
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
