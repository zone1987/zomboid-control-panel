<?php

declare(strict_types=1);

namespace App\Security\Webauthn;

use App\Entity\WebauthnCredential;

final class CredentialPayload
{
    /** @return array<string, mixed> */
    public static function from(WebauthnCredential $credential): array
    {
        return [
            'id' => $credential->getId()->toRfc4122(),
            'name' => $credential->getName(),
            'createdAt' => $credential->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'lastUsedAt' => $credential->getLastUsedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
