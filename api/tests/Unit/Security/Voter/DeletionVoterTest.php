<?php

namespace App\Tests\Unit\Security\Voter;

use App\Entity\ScheduledDeletion;
use App\Entity\User;
use App\Security\Voter\DeletionVoter;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Unit tests for DeletionVoter.
 *
 * The DeletionVoter governs DELETION_EDIT and DELETION_CANCEL on ScheduledDeletion:
 * - ROLE_ADMIN: can edit/cancel any deletion
 * - ROLE_ADVANCED_USER: can only edit/cancel their own deletions (createdBy->getId()->equals(user->getId()))
 * - ROLE_USER: denied
 * - ROLE_GUEST: denied
 * - No user (anonymous): denied
 */
class DeletionVoterTest extends TestCase
{
    private DeletionVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new DeletionVoter();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function createUserWithId(string $role, ?Uuid $id = null): User
    {
        $uuid = $id ?? Uuid::v4();

        $user = $this->createStub(User::class);
        $user->method('getRole')->willReturn($role);
        $user->method('getId')->willReturn($uuid);

        return $user;
    }

    private function createToken(?User $user = null): TokenInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }

    private function createDeletion(User $owner): ScheduledDeletion
    {
        $deletion = $this->createStub(ScheduledDeletion::class);
        $deletion->method('getCreatedBy')->willReturn($owner);

        return $deletion;
    }

    private function vote(string $attribute, mixed $subject, TokenInterface $token): int
    {
        return $this->voter->vote($token, $subject, [$attribute]);
    }

    // ---------------------------------------------------------------
    // supports() behaviour
    // ---------------------------------------------------------------

    public function testSupportsDeletionEditOnScheduledDeletion(): void
    {
        $owner = $this->createUserWithId('ROLE_ADMIN');
        $deletion = $this->createDeletion($owner);
        $token = $this->createToken($owner);

        $result = $this->vote(DeletionVoter::EDIT, $deletion, $token);

        $this->assertNotEquals(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    public function testSupportsDeletionCancelOnScheduledDeletion(): void
    {
        $owner = $this->createUserWithId('ROLE_ADMIN');
        $deletion = $this->createDeletion($owner);
        $token = $this->createToken($owner);

        $result = $this->vote(DeletionVoter::CANCEL, $deletion, $token);

        $this->assertNotEquals(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    public function testAbstainsOnUnsupportedAttribute(): void
    {
        $owner = $this->createUserWithId('ROLE_ADMIN');
        $deletion = $this->createDeletion($owner);
        $token = $this->createToken($owner);

        $result = $this->vote('SOME_OTHER_ATTRIBUTE', $deletion, $token);

        $this->assertEquals(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    public function testAbstainsOnNonScheduledDeletionSubject(): void
    {
        $owner = $this->createUserWithId('ROLE_ADMIN');
        $token = $this->createToken($owner);

        $result = $this->vote(DeletionVoter::CANCEL, new stdClass(), $token);

        $this->assertEquals(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    // ---------------------------------------------------------------
    // DELETION_CANCEL — Admin
    // ---------------------------------------------------------------

    public function testAdminCanCancelAnyDeletion(): void
    {
        $owner = $this->createUserWithId('ROLE_ADVANCED_USER');
        $deletion = $this->createDeletion($owner);

        $admin = $this->createUserWithId('ROLE_ADMIN');
        $token = $this->createToken($admin);

        $result = $this->vote(DeletionVoter::CANCEL, $deletion, $token);

        $this->assertEquals(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testAdminCanCancelOwnDeletion(): void
    {
        $admin = $this->createUserWithId('ROLE_ADMIN');
        $deletion = $this->createDeletion($admin);
        $token = $this->createToken($admin);

        $result = $this->vote(DeletionVoter::CANCEL, $deletion, $token);

        $this->assertEquals(VoterInterface::ACCESS_GRANTED, $result);
    }

    // ---------------------------------------------------------------
    // DELETION_CANCEL — Advanced user (ownership check)
    // ---------------------------------------------------------------

    public function testAdvancedUserCanCancelOwnDeletion(): void
    {
        $sharedId = Uuid::v4();
        $owner = $this->createUserWithId('ROLE_ADVANCED_USER', $sharedId);
        $deletion = $this->createDeletion($owner);

        // Same user instance (same ID) authenticating
        $sameUser = $this->createUserWithId('ROLE_ADVANCED_USER', $sharedId);
        $token = $this->createToken($sameUser);

        $result = $this->vote(DeletionVoter::CANCEL, $deletion, $token);

        $this->assertEquals(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testAdvancedUserCannotCancelOthersDeletion(): void
    {
        $owner = $this->createUserWithId('ROLE_ADVANCED_USER');
        $deletion = $this->createDeletion($owner);

        // Different advanced user (different UUID)
        $otherUser = $this->createUserWithId('ROLE_ADVANCED_USER');
        $token = $this->createToken($otherUser);

        $result = $this->vote(DeletionVoter::CANCEL, $deletion, $token);

        $this->assertEquals(VoterInterface::ACCESS_DENIED, $result);
    }

    // ---------------------------------------------------------------
    // DELETION_EDIT — Admin
    // ---------------------------------------------------------------

    public function testAdminCanEditAnyDeletion(): void
    {
        $owner = $this->createUserWithId('ROLE_ADVANCED_USER');
        $deletion = $this->createDeletion($owner);

        $admin = $this->createUserWithId('ROLE_ADMIN');
        $token = $this->createToken($admin);

        $result = $this->vote(DeletionVoter::EDIT, $deletion, $token);

        $this->assertEquals(VoterInterface::ACCESS_GRANTED, $result);
    }

    // ---------------------------------------------------------------
    // DELETION_EDIT — Advanced user (ownership check)
    // ---------------------------------------------------------------

    public function testAdvancedUserCanEditOwnDeletion(): void
    {
        $sharedId = Uuid::v4();
        $owner = $this->createUserWithId('ROLE_ADVANCED_USER', $sharedId);
        $deletion = $this->createDeletion($owner);

        $sameUser = $this->createUserWithId('ROLE_ADVANCED_USER', $sharedId);
        $token = $this->createToken($sameUser);

        $result = $this->vote(DeletionVoter::EDIT, $deletion, $token);

        $this->assertEquals(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testAdvancedUserCannotEditOthersDeletion(): void
    {
        $owner = $this->createUserWithId('ROLE_ADVANCED_USER');
        $deletion = $this->createDeletion($owner);

        $otherUser = $this->createUserWithId('ROLE_ADVANCED_USER');
        $token = $this->createToken($otherUser);

        $result = $this->vote(DeletionVoter::EDIT, $deletion, $token);

        $this->assertEquals(VoterInterface::ACCESS_DENIED, $result);
    }

    // ---------------------------------------------------------------
    // Denied roles
    // ---------------------------------------------------------------

    public function testStandardUserCannotCancelDeletion(): void
    {
        $owner = $this->createUserWithId('ROLE_ADVANCED_USER');
        $deletion = $this->createDeletion($owner);

        $user = $this->createUserWithId('ROLE_USER');
        $token = $this->createToken($user);

        $result = $this->vote(DeletionVoter::CANCEL, $deletion, $token);

        $this->assertEquals(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testGuestCannotCancelDeletion(): void
    {
        $owner = $this->createUserWithId('ROLE_ADVANCED_USER');
        $deletion = $this->createDeletion($owner);

        $guest = $this->createUserWithId('ROLE_GUEST');
        $token = $this->createToken($guest);

        $result = $this->vote(DeletionVoter::CANCEL, $deletion, $token);

        $this->assertEquals(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testAnonymousUserCannotCancelDeletion(): void
    {
        $owner = $this->createUserWithId('ROLE_ADVANCED_USER');
        $deletion = $this->createDeletion($owner);

        $token = $this->createToken();

        $result = $this->vote(DeletionVoter::CANCEL, $deletion, $token);

        $this->assertEquals(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testStandardUserCannotEditDeletion(): void
    {
        $owner = $this->createUserWithId('ROLE_ADVANCED_USER');
        $deletion = $this->createDeletion($owner);

        $user = $this->createUserWithId('ROLE_USER');
        $token = $this->createToken($user);

        $result = $this->vote(DeletionVoter::EDIT, $deletion, $token);

        $this->assertEquals(VoterInterface::ACCESS_DENIED, $result);
    }
}
