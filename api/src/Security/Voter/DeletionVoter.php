<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\ScheduledDeletion;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter for scheduled deletion ownership.
 *
 * - ROLE_ADMIN: can modify/cancel any deletion
 * - ROLE_ADVANCED_USER: can only modify/cancel their own deletions
 * - Others: denied
 */
class DeletionVoter extends Voter
{
    public const EDIT = 'DELETION_EDIT';
    public const CANCEL = 'DELETION_CANCEL';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::EDIT, self::CANCEL], true)
            && $subject instanceof ScheduledDeletion;
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var ScheduledDeletion $deletion */
        $deletion = $subject;

        // Admin can do anything
        if ($user->getRole() === 'ROLE_ADMIN') {
            return true;
        }

        // Advanced user can only modify/cancel their own
        if ($user->getRole() === 'ROLE_ADVANCED_USER') {
            $creator = $deletion->getCreatedBy();

            return $creator !== null && $creator->getId()->equals($user->getId());
        }

        return false;
    }
}
