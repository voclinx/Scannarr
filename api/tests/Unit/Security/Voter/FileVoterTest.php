<?php

namespace App\Tests\Unit\Security\Voter;

use App\Entity\MediaFile;
use App\Entity\User;
use App\Security\Voter\FileVoter;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Unit tests for FileVoter.
 *
 * The FileVoter governs FILE_DELETE permissions on MediaFile entities:
 * - ROLE_ADMIN: allowed
 * - ROLE_ADVANCED_USER: allowed
 * - ROLE_USER: denied
 * - ROLE_GUEST: denied
 * - No user (anonymous): denied
 */
class FileVoterTest extends TestCase
{
    private FileVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new FileVoter();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function createUser(string $role): User
    {
        $user = $this->createStub(User::class);
        $user->method('getRole')->willReturn($role);

        return $user;
    }

    private function createToken(?User $user = null): TokenInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }

    private function vote(string $attribute, mixed $subject, TokenInterface $token): int
    {
        return $this->voter->vote($token, $subject, [$attribute]);
    }

    // ---------------------------------------------------------------
    // supports() behaviour
    // ---------------------------------------------------------------

    public function testSupportsFileDeleteOnMediaFile(): void
    {
        $file = new MediaFile();

        $result = $this->vote(FileVoter::DELETE, $file, $this->createToken($this->createUser('ROLE_ADMIN')));

        // Should not abstain — it supports this combination
        $this->assertNotEquals(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    public function testAbstainsOnUnsupportedAttribute(): void
    {
        $file = new MediaFile();

        $result = $this->vote('SOME_OTHER_ATTRIBUTE', $file, $this->createToken($this->createUser('ROLE_ADMIN')));

        $this->assertEquals(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    public function testAbstainsOnNonMediaFileSubject(): void
    {
        $result = $this->vote(FileVoter::DELETE, new stdClass(), $this->createToken($this->createUser('ROLE_ADMIN')));

        $this->assertEquals(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    // ---------------------------------------------------------------
    // Permission decisions
    // ---------------------------------------------------------------

    public function testAdminCanDeleteFile(): void
    {
        $user = $this->createUser('ROLE_ADMIN');
        $token = $this->createToken($user);
        $file = new MediaFile();

        $result = $this->vote(FileVoter::DELETE, $file, $token);

        $this->assertEquals(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testAdvancedUserCanDeleteFile(): void
    {
        $user = $this->createUser('ROLE_ADVANCED_USER');
        $token = $this->createToken($user);
        $file = new MediaFile();

        $result = $this->vote(FileVoter::DELETE, $file, $token);

        $this->assertEquals(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testStandardUserCannotDeleteFile(): void
    {
        $user = $this->createUser('ROLE_USER');
        $token = $this->createToken($user);
        $file = new MediaFile();

        $result = $this->vote(FileVoter::DELETE, $file, $token);

        $this->assertEquals(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testGuestCannotDeleteFile(): void
    {
        $user = $this->createUser('ROLE_GUEST');
        $token = $this->createToken($user);
        $file = new MediaFile();

        $result = $this->vote(FileVoter::DELETE, $file, $token);

        $this->assertEquals(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testAnonymousUserCannotDeleteFile(): void
    {
        $token = $this->createToken();
        $file = new MediaFile();

        $result = $this->vote(FileVoter::DELETE, $file, $token);

        $this->assertEquals(VoterInterface::ACCESS_DENIED, $result);
    }
}
