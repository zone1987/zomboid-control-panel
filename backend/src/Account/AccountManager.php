<?php

declare(strict_types=1);

namespace App\Account;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Changes to accounts other than one's own, with the two rules that keep
 * an installation reachable: the last administrator cannot be removed,
 * and nobody can lock themselves out.
 */
final readonly class AccountManager
{
    public const ASSIGNABLE_ROLES = [User::ROLE_USER, User::ROLE_SERVER_ADMIN, User::ROLE_ADMIN];

    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $hasher,
    ) {
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(User $subject, array $roles, User $actor): void
    {
        $roles = $this->normaliseRoles($roles);

        if ($this->isSelf($subject, $actor) && !\in_array(User::ROLE_ADMIN, $roles, true)) {
            throw new SelfModification('users.cannotDemoteSelf');
        }

        if ($this->wouldRemoveLastAdministrator($subject, \in_array(User::ROLE_ADMIN, $roles, true))) {
            throw new LastAdministrator();
        }

        $subject->setRoles($roles);
        $this->entityManager->flush();
    }

    public function setActive(User $subject, bool $active, User $actor): void
    {
        if ($this->isSelf($subject, $actor) && !$active) {
            throw new SelfModification('users.cannotDeactivateSelf');
        }

        if (!$active && $this->wouldRemoveLastAdministrator($subject, false)) {
            throw new LastAdministrator();
        }

        $subject->setActive($active);
        $this->entityManager->flush();
    }

    public function updateProfile(User $subject, ?string $displayName, ?string $email): void
    {
        if ($displayName !== null && trim($displayName) !== '') {
            $subject->setDisplayName(trim($displayName));
        }

        if ($email !== null && trim($email) !== '') {
            $subject->setEmail(strtolower(trim($email)));
        }

        $this->entityManager->flush();
    }

    /** Lets an administrator set a password for someone who cannot sign in. */
    public function setPassword(User $subject, string $plain): void
    {
        $subject->setPassword($this->hasher->hashPassword($subject, $plain));
        $this->entityManager->flush();
    }

    public function delete(User $subject, User $actor): void
    {
        if ($this->isSelf($subject, $actor)) {
            throw new SelfModification('users.cannotDeleteSelf');
        }

        if ($this->wouldRemoveLastAdministrator($subject, false)) {
            throw new LastAdministrator();
        }

        $this->entityManager->remove($subject);
        $this->entityManager->flush();
    }

    /**
     * True when the subject is the only administrator left and would stop
     * being one — the state that leaves nobody able to manage the panel.
     */
    private function wouldRemoveLastAdministrator(User $subject, bool $staysAdministrator): bool
    {
        if ($staysAdministrator || !\in_array(User::ROLE_ADMIN, $subject->getRoles(), true)) {
            return false;
        }

        return $this->users->countActiveAdministrators() <= 1;
    }

    private function isSelf(User $subject, User $actor): bool
    {
        return $subject->getId()->equals($actor->getId());
    }

    /**
     * @param list<string> $roles
     *
     * @return list<string>
     */
    private function normaliseRoles(array $roles): array
    {
        $kept = array_values(array_intersect(self::ASSIGNABLE_ROLES, $roles));

        return $kept === [] ? [User::ROLE_USER] : $kept;
    }
}
