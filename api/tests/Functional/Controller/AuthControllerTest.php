<?php

namespace App\Tests\Functional\Controller;

use App\Entity\RefreshToken;
use App\Entity\Setting;
use App\Entity\User;
use App\Tests\AbstractApiTestCase;
use DateTime;

class AuthControllerTest extends AbstractApiTestCase
{
    // -----------------------------------------------------------------------
    // TEST-AUTH-001 : Setup initial — créer le premier admin
    // -----------------------------------------------------------------------

    /**
     * @testdox TEST-AUTH-001 Setup initial - créer le premier admin quand setup_completed=false
     */
    public function testSetupInitialCreatesAdmin(): void
    {
        // Ensure no setup_completed setting exists (fresh state)
        $existing = $this->em->getRepository(Setting::class)->findOneBy(['settingKey' => 'setup_completed']);
        if ($existing instanceof Setting) {
            $this->em->remove($existing);
            $this->em->flush();
        }

        $this->apiPost('/api/v1/auth/setup', [
            'email' => 'admin@scanarr.io',
            'username' => 'admin',
            'password' => 'Str0ngP@ss!',
        ]);

        $this->assertResponseStatusCode(201);

        $data = $this->getResponseData();
        $this->assertArrayHasKey('data', $data);
        $this->assertEquals('admin@scanarr.io', $data['data']['email']);
        $this->assertEquals('admin', $data['data']['username']);
        $this->assertEquals('ROLE_ADMIN', $data['data']['role']);
        $this->assertArrayHasKey('id', $data['data']);

        // Verify the user is persisted with a hashed password (not plaintext)
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'admin@scanarr.io']);
        $this->assertNotNull($user);
        $this->assertNotEquals('Str0ngP@ss!', $user->getPassword(), 'Password must be hashed, not stored in plaintext');
        $this->assertStringStartsWith('$', $user->getPassword(), 'Hashed password should start with $ (bcrypt/argon2 format)');

        // Verify the setup_completed setting is now true
        $setupSetting = $this->em->getRepository(Setting::class)->findOneBy(['settingKey' => 'setup_completed']);
        $this->assertNotNull($setupSetting);
        $this->assertEquals('true', $setupSetting->getSettingValue());
    }

    // -----------------------------------------------------------------------
    // TEST-AUTH-002 : Setup initial — refuser si déjà complété
    // -----------------------------------------------------------------------

    /**
     * @testdox TEST-AUTH-002 Setup initial - refuser si déjà complété
     */
    public function testSetupRefusedIfAlreadyCompleted(): void
    {
        $this->setSetting('setup_completed', 'true', 'boolean');

        $this->apiPost('/api/v1/auth/setup', [
            'email' => 'another@scanarr.io',
            'username' => 'another',
            'password' => 'Str0ngP@ss!',
        ]);

        $this->assertResponseStatusCode(403);

        $data = $this->getResponseData();
        $this->assertArrayHasKey('error', $data);
        $this->assertEquals('Setup already completed', $data['error']['message']);
    }

    // -----------------------------------------------------------------------
    // TEST-AUTH-003 : Login — credentials valides
    // -----------------------------------------------------------------------

    /**
     * @testdox TEST-AUTH-003 Login - credentials valides
     */
    public function testLoginWithValidCredentials(): void
    {
        $this->createUser(
            email: 'user@scanarr.io',
            username: 'testuser',
            password: 'MyP@ssw0rd',
        );

        $this->apiPost('/api/v1/auth/login', [
            'email' => 'user@scanarr.io',
            'password' => 'MyP@ssw0rd',
        ]);

        $this->assertResponseStatusCode(200);

        $data = $this->getResponseData();
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('access_token', $data['data']);
        $this->assertArrayHasKey('refresh_token', $data['data']);
        $this->assertNotEmpty($data['data']['access_token']);
        $this->assertNotEmpty($data['data']['refresh_token']);
        $this->assertArrayHasKey('user', $data['data']);
        $this->assertEquals('testuser', $data['data']['user']['username']);
    }

    // -----------------------------------------------------------------------
    // TEST-AUTH-004 : Login — credentials invalides
    // -----------------------------------------------------------------------

    /**
     * @testdox TEST-AUTH-004 Login - credentials invalides
     */
    public function testLoginWithInvalidCredentials(): void
    {
        $this->createUser(
            email: 'user@scanarr.io',
            username: 'testuser',
            password: 'MyP@ssw0rd',
        );

        $this->apiPost('/api/v1/auth/login', [
            'email' => 'user@scanarr.io',
            'password' => 'WrongPassword',
        ]);

        $this->assertResponseStatusCode(401);

        $data = $this->getResponseData();
        $this->assertArrayHasKey('error', $data);
        $this->assertEquals('Invalid credentials', $data['error']['message']);
    }

    // -----------------------------------------------------------------------
    // TEST-AUTH-005 : Login — compte désactivé
    // -----------------------------------------------------------------------

    /**
     * @testdox TEST-AUTH-005 Login - compte désactivé
     */
    public function testLoginWithDisabledAccount(): void
    {
        $this->createUser(
            email: 'disabled@scanarr.io',
            username: 'disableduser',
            password: 'MyP@ssw0rd',
            isActive: false,
        );

        $this->apiPost('/api/v1/auth/login', [
            'email' => 'disabled@scanarr.io',
            'password' => 'MyP@ssw0rd',
        ]);

        $this->assertResponseStatusCode(401);

        $data = $this->getResponseData();
        $this->assertArrayHasKey('error', $data);
        $this->assertEquals('Account is disabled', $data['error']['message']);
    }

    // -----------------------------------------------------------------------
    // TEST-AUTH-006 : Refresh token — token valide
    // -----------------------------------------------------------------------

    /**
     * @testdox TEST-AUTH-006 Refresh token - token valide
     */
    public function testRefreshTokenWithValidToken(): void
    {
        $user = $this->createUser(
            email: 'user@scanarr.io',
            username: 'testuser',
            password: 'MyP@ssw0rd',
        );

        // Create a refresh token directly in DB (avoids cross-request transaction issues)
        $refreshTokenString = bin2hex(random_bytes(32));
        $refreshToken = new RefreshToken();
        $refreshToken->setRefreshToken($refreshTokenString);
        $refreshToken->setUsername($user->getEmail());
        $refreshToken->setValid(new DateTime('+1 hour'));
        $this->em->persist($refreshToken);
        $this->em->flush();

        // Use the refresh token to obtain a new access token
        $this->apiPost('/api/v1/auth/refresh', [
            'refresh_token' => $refreshTokenString,
        ]);

        $this->assertResponseStatusCode(200);

        $data = $this->getResponseData();
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('access_token', $data['data']);
        $this->assertNotEmpty($data['data']['access_token']);
        $this->assertArrayHasKey('refresh_token', $data['data']);
        $this->assertNotEmpty($data['data']['refresh_token']);
    }

    // -----------------------------------------------------------------------
    // TEST-AUTH-007 : Refresh token — token expiré/invalide
    // -----------------------------------------------------------------------

    /**
     * @testdox TEST-AUTH-007 Refresh token - token expiré ou invalide
     */
    public function testRefreshTokenWithInvalidToken(): void
    {
        $this->apiPost('/api/v1/auth/refresh', [
            'refresh_token' => 'completely-invalid-token-that-does-not-exist',
        ]);

        $this->assertResponseStatusCode(401);

        $data = $this->getResponseData();
        $this->assertArrayHasKey('error', $data);
    }

    // -----------------------------------------------------------------------
    // TEST-AUTH-008 : Me — utilisateur connecté
    // -----------------------------------------------------------------------

    /**
     * @testdox TEST-AUTH-008 Me - utilisateur connecté
     */
    public function testMeWithAuthenticatedUser(): void
    {
        $user = $this->createUser(
            email: 'user@scanarr.io',
            username: 'testuser',
            password: 'MyP@ssw0rd',
        );

        $this->authenticateAs($user);

        $this->apiGet('/api/v1/auth/me');

        $this->assertResponseStatusCode(200);

        $data = $this->getResponseData();
        $this->assertArrayHasKey('data', $data);
        $this->assertEquals((string)$user->getId(), $data['data']['id']);
        $this->assertEquals('user@scanarr.io', $data['data']['email']);
        $this->assertEquals('testuser', $data['data']['username']);
        $this->assertEquals('ROLE_USER', $data['data']['role']);
        $this->assertTrue($data['data']['is_active']);
        $this->assertArrayHasKey('created_at', $data['data']);
    }
}
