<?php

declare(strict_types=1);

namespace App\Security\Http;

use App\Entity\User;

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
            'locale' => $user->getLocale(),
            'twoFactorEnabled' => $user->isTotpAuthenticationEnabled(),
            'passkeyCount' => $user->getWebauthnCredentials()->count(),
            'linkedProviders' => $user->getOauthIdentities()
                ->map(static fn ($identity): string => $identity->getProvider())
                ->getValues(),
        ];
    }
}
