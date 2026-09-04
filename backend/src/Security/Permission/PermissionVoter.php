<?php

declare(strict_types=1);

namespace App\Security\Permission;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Grants a permission from an assigned role, or from a legacy role name.
 *
 * The legacy fallback is what lets checks move over one at a time: an
 * installation that predates configurable roles keeps working, because
 * ROLE_ADMIN still carries everything it used to carry.
 *
 * @extends Voter<string, mixed>
 */
final class PermissionVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return Permission::tryFrom($attribute) !== null;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        $permission = Permission::tryFrom($attribute);

        if (!$user instanceof User || $permission === null) {
            return false;
        }

        if ($user->hasPermission($permission)) {
            return true;
        }

        return self::legacyRoleGrants($user->getRoles(), $permission);
    }

    /**
     * @param list<string> $roles
     */
    public static function legacyRoleGrants(array $roles, Permission $permission): bool
    {
        if (\in_array(User::ROLE_ADMIN, $roles, true)) {
            return true;
        }

        if (!\in_array(User::ROLE_SERVER_ADMIN, $roles, true)) {
            return false;
        }

        return !\in_array($permission, [
            Permission::InviteUsers,
            Permission::ManageUsers,
            Permission::EditSettings,
        ], true);
    }
}
