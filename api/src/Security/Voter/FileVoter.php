<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\MediaFile;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter for file deletion permissions.
 *
 * - ROLE_GUEST / ROLE_USER: cannot delete
 * - ROLE_ADVANCED_USER: can delete
 * - ROLE_ADMIN: can delete
 */
class FileVoter extends Voter
{
    public const DELETE = 'FILE_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::DELETE && $subject instanceof MediaFile;
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

        $role = $user->getRole();

        // Admin and Advanced User can delete files
        return in_array($role, ['ROLE_ADMIN', 'ROLE_ADVANCED_USER'], true);
    }
}
