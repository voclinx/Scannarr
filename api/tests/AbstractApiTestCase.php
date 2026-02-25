<?php

namespace App\Tests;

use App\Entity\Setting;
use App\Entity\User;
use App\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

abstract class AbstractApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        // Start transaction for test isolation
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        // Rollback transaction to reset DB state
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }

        parent::tearDown();
    }

    protected function createUser(
        string $email = 'test@example.com',
        string $username = 'testuser',
        string $password = 'password123',
        string $role = 'ROLE_USER',
        bool $isActive = true,
    ): User {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setUsername($username);
        $user->setRole($role);
        $user->setIsActive($isActive);
        $user->setPassword($hasher->hashPassword($user, $password));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    protected function createAdmin(
        string $email = 'admin@example.com',
        string $username = 'admin',
        string $password = 'password123',
    ): User {
        return $this->createUser($email, $username, $password, 'ROLE_ADMIN');
    }

    protected function createAdvancedUser(
        string $email = 'advanced@example.com',
        string $username = 'advanced',
        string $password = 'password123',
    ): User {
        return $this->createUser($email, $username, $password, 'ROLE_ADVANCED_USER');
    }

    protected function createGuest(
        string $email = 'guest@example.com',
        string $username = 'guest',
        string $password = 'password123',
    ): User {
        return $this->createUser($email, $username, $password, 'ROLE_GUEST');
    }

    protected function getJwtToken(User $user): string
    {
        $jwtManager = static::getContainer()->get(JWTTokenManagerInterface::class);

        return $jwtManager->create($user);
    }

    protected function authenticateAs(User $user): void
    {
        $token = $this->getJwtToken($user);
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $token);
    }

    protected function apiGet(string $uri, array $parameters = []): void
    {
        $this->client->request('GET', $uri, $parameters, [], [
            'CONTENT_TYPE' => 'application/json',
        ]);
    }

    protected function apiPost(string $uri, array $data = []): void
    {
        $this->client->request('POST', $uri, [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($data));
    }

    protected function apiPut(string $uri, array $data = []): void
    {
        $this->client->request('PUT', $uri, [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($data));
    }

    protected function apiDelete(string $uri, array $data = []): void
    {
        $this->client->request('DELETE', $uri, [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], !empty($data) ? json_encode($data) : null);
    }

    protected function getResponseData(): array
    {
        $content = $this->client->getResponse()->getContent();

        return json_decode($content, true) ?? [];
    }

    protected function setSetting(string $key, ?string $value, string $type = 'string'): void
    {
        $setting = $this->em->getRepository(Setting::class)->findOneBy(['settingKey' => $key]);

        if (!$setting) {
            $setting = new Setting();
            $setting->setSettingKey($key);
        }

        $setting->setSettingValue($value);
        $setting->setSettingType($type);
        $this->em->persist($setting);
        $this->em->flush();
    }

    protected function assertResponseStatusCode(int $expectedStatusCode): void
    {
        $this->assertEquals(
            $expectedStatusCode,
            $this->client->getResponse()->getStatusCode(),
            sprintf(
                'Expected HTTP %d, got %d. Response: %s',
                $expectedStatusCode,
                $this->client->getResponse()->getStatusCode(),
                substr($this->client->getResponse()->getContent(), 0, 500),
            ),
        );
    }
}
