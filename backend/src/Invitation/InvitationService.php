<?php

declare(strict_types=1);

namespace App\Invitation;

use App\Entity\Invitation;
use App\Entity\User;
use App\Mail\ConfiguredMailer;
use App\Repository\InvitationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class InvitationService
{
    private const VALID_FOR = '+7 days';

    public function __construct(
        private InvitationRepository $invitations,
        private UserRepository $users,
        private EntityManagerInterface $entityManager,
        private ConfiguredMailer $mailer,
        private UserPasswordHasherInterface $passwordHasher,
        private LoggerInterface $logger,
        private string $publicUrl,
    ) {
    }

    /**
     * @param list<string> $roles
     *
     * @throws AccountAlreadyExists
     */
    public function invite(string $email, array $roles, ?User $invitedBy): Invitation
    {
        $email = mb_strtolower(trim($email));

        if ($this->users->findOneBy(['email' => $email]) !== null) {
            throw new AccountAlreadyExists($email);
        }

        // A pending invitation for the same address is replaced rather than
        // duplicated, so only the newest link works.
        foreach ($this->invitations->findBy(['email' => $email, 'acceptedAt' => null]) as $stale) {
            $this->entityManager->remove($stale);
        }

        $token = bin2hex(random_bytes(32));

        $invitation = new Invitation(
            $email,
            hash('sha256', $token),
            $roles,
            $invitedBy,
            new \DateTimeImmutable(self::VALID_FOR),
        );

        $this->entityManager->persist($invitation);
        $this->entityManager->flush();

        $this->deliver($invitation, $token, $invitedBy);

        return $invitation;
    }

    public function findPendingByToken(string $token): ?Invitation
    {
        $invitation = $this->invitations->findOneBy(['tokenHash' => hash('sha256', $token)]);

        return $invitation?->isPending() === true ? $invitation : null;
    }

    /**
     * @throws AccountAlreadyExists
     */
    public function accept(Invitation $invitation, string $displayName, string $password): User
    {
        if ($this->users->findOneBy(['email' => $invitation->getEmail()]) !== null) {
            throw new AccountAlreadyExists($invitation->getEmail());
        }

        $user = new User($invitation->getEmail(), $displayName);
        $user->setRoles($invitation->getRoles());
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $invitation->accept();

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    /**
     * Sending is best-effort: the invitation stands even if the mail fails,
     * so an administrator can hand over the link themselves.
     */
    private function deliver(Invitation $invitation, string $token, ?User $invitedBy): void
    {
        if (!$this->mailer->isConfigured()) {
            $this->logger->warning('Invitation created but no mail account is configured.', [
                'email' => $invitation->getEmail(),
            ]);

            return;
        }

        $link = rtrim($this->publicUrl, '/').'/app/invitation/'.$token;

        $email = new Email()
            ->to($invitation->getEmail())
            ->subject('You have been invited to ZomboidControl')
            ->text($this->body($link, $invitedBy))
        ;

        try {
            $this->mailer->send($email);
        } catch (\Throwable $exception) {
            $this->logger->error('Invitation mail could not be sent.', [
                'email' => $invitation->getEmail(),
                'exception' => $exception,
            ]);
        }
    }

    private function body(string $link, ?User $invitedBy): string
    {
        $who = $invitedBy?->getDisplayName();

        return sprintf(
            "%s\n\nOpen this link to choose a password and sign in:\n\n%s\n\nThe link expires in seven days.\n",
            $who === null
                ? 'You have been invited to ZomboidControl.'
                : sprintf('%s invited you to ZomboidControl.', $who),
            $link,
        );
    }
}
