<?php

declare(strict_types=1);

namespace App\Security\Http;

use App\Entity\User;
use App\Security\Permission\Permission;
use App\Security\Permission\PermissionVoter;

/**
 * The single shape in which an account is handed to the frontend.
 */
final class UserPayload
{
    /** @return array<string, mixed> */
    public static function from(User $user): array
    {
        return [
            'id' => $user->getId()->toRfc4122(),
            'email' => $user->getEmail(),
            'displayName' => $user->getDisplayName(),
            'roles' => $user->getRoles(),
            // Everything the user may actually do, from assigned roles and
            // from the legacy role names alike, so the interface has one
            // answer to consult rather than two.
            'permissions' => self::permissionsOf($user),
            'locale' => $user->getLocale(),
            'twoFactorEnabled' => $user->isTotpAuthenticationEnabled(),
            'passkeyCount' => $user->getWebauthnCredentials()->count(),
            'linkedProviders' => $user->getOauthIdentities()
                ->map(static fn ($identity): string => $identity->getProvider())
                ->getValues(),
        ];
    }

    /** @return list<string> */
    private static function permissionsOf(User $user): array
    {
        $roles = $user->getRoles();
        $held = [];

        foreach (Permission::cases() as $permission) {
            if ($user->hasPermission($permission) || PermissionVoter::legacyRoleGrants($roles, $permission)) {
                $held[] = $permission->value;
            }
        }

        return $held;
    }
}
