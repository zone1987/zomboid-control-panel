<?php

declare(strict_types=1);

namespace App\Security\OAuth;

use App\Entity\OAuthIdentity;
use App\Entity\User;
use App\Repository\OAuthIdentityRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Resolves an external identity to a local account.
 *
 * Access is by invitation, so an unknown identity never creates an
 * account: it either attaches to the signed-in user, matches an already
 * linked identity, or is refused.
 */
final readonly class IdentityLinker
{
    public function __construct(
        private OAuthIdentityRepository $identities,
        private UserRepository $users,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function findUser(string $provider, string $providerUserId): ?User
    {
        return $this->identities
            ->findOneBy(['provider' => $provider, 'providerUserId' => $providerUserId])
            ?->getUser();
    }

    /**
     * Attaches an identity to an account already signed in.
     *
     * @throws IdentityAlreadyLinked when another account claims it
     */
    public function link(User $user, string $provider, string $providerUserId, ?string $label = null): OAuthIdentity
    {
        $existing = $this->identities->findOneBy([
            'provider' => $provider,
            'providerUserId' => $providerUserId,
        ]);

        if ($existing instanceof OAuthIdentity) {
            if (!$existing->getUser()->getId()->equals($user->getId())) {
                throw new IdentityAlreadyLinked($provider);
            }

            return $existing;
        }

        $identity = new OAuthIdentity($user, $provider, $providerUserId, $label);

        $this->entityManager->persist($identity);
        $this->entityManager->flush();

        return $identity;
    }

    public function unlink(User $user, string $provider): bool
    {
        $identity = $this->identities->findOneBy(['user' => $user, 'provider' => $provider]);

        if (!$identity instanceof OAuthIdentity) {
            return false;
        }

        $this->entityManager->remove($identity);
        $this->entityManager->flush();

        return true;
    }

    /**
     * True while the account keeps at least one other way to sign in.
     */
    public function canUnlink(User $user, string $provider): bool
    {
        if ($user->getPassword() !== null || $user->getWebauthnCredentials()->count() > 0) {
            return true;
        }

        return $this->identities->count(['user' => $user]) > 1;
    }

    /**
     * The invited account waiting on this address, if there is one.
     *
     * Matching the address alone was too generous: an account that has
     * been in use for months would accept whoever later controls a
     * Google account with the same address -- somebody who left the
     * company, or a domain that changed hands. That is a sign-in nobody
     * granted.
     *
     * An invited account has no password yet; setting one is what
     * finishes the invitation. So a null password *is* the state
     * "invited, not yet activated", and matching only those keeps the
     * convenience the invitation is for without opening the rest.
     */
    public function findInvitedByEmail(string $email): ?User
    {
        $user = $this->users->findOneBy(['email' => $email]);

        // A passkey counts as activated too: the same reasoning as
        // canUnlink, which already treats either as a way in.
        if (!$user instanceof User) {
            return null;
        }

        if ($user->getPassword() !== null || $user->getWebauthnCredentials()->count() > 0) {
            return null;
        }

        // An account already linked to some provider is in use, whatever
        // its password state -- it is not waiting on an invitation.
        if ($this->identities->count(['user' => $user]) > 0) {
            return null;
        }

        return $user;
    }
}
