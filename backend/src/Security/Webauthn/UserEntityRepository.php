<?php

declare(strict_types=1);

namespace App\Security\Webauthn;

use App\Entity\User;
use App\Repository\UserRepository;
use Webauthn\Bundle\Repository\PublicKeyCredentialUserEntityRepositoryInterface;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Bridges our User entity to WebAuthn's notion of a user.
 *
 * The handle is the account UUID rather than the email address, so
 * changing an address never invalidates existing passkeys.
 */
final readonly class UserEntityRepository implements PublicKeyCredentialUserEntityRepositoryInterface
{
    public function __construct(private UserRepository $users)
    {
    }

    public function findOneByUsername(string $username): ?PublicKeyCredentialUserEntity
    {
        $user = $this->users->findOneBy(['email' => $username]);

        return $user === null ? null : $this->toUserEntity($user);
    }

    public function findOneByUserHandle(string $userHandle): ?PublicKeyCredentialUserEntity
    {
        $user = $this->users->find($userHandle);

        return $user === null ? null : $this->toUserEntity($user);
    }

    private function toUserEntity(User $user): PublicKeyCredentialUserEntity
    {
        return PublicKeyCredentialUserEntity::create(
            $user->getEmail(),
            $user->getId()->toRfc4122(),
            $user->getDisplayName(),
        );
    }
}
