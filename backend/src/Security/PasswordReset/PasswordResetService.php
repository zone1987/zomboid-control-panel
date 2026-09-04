<?php

declare(strict_types=1);

namespace App\Security\PasswordReset;

use App\Entity\PasswordResetToken;
use App\Entity\User;
use App\Mail\ConfiguredMailer;
use App\Repository\PasswordResetTokenRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class PasswordResetService
{
    private const VALID_FOR = '+1 hour';

    public function __construct(
        private UserRepository $users,
        private PasswordResetTokenRepository $tokens,
        private EntityManagerInterface $entityManager,
        private ConfiguredMailer $mailer,
        private UserPasswordHasherInterface $passwordHasher,
        private LoggerInterface $logger,
        private string $publicUrl,
    ) {
    }

    /**
     * Always succeeds from the caller's point of view. Reporting that an
     * address is unknown would turn this into an account-enumeration tool.
     */
    public function request(string $email): void
    {
        $user = $this->users->findOneBy(['email' => mb_strtolower(trim($email))]);

        if (!$user instanceof User || !$user->isActive()) {
            return;
        }

        // Only the newest link may work.
        $this->tokens->removeAllForUser($user);

        $token = bin2hex(random_bytes(32));

        $this->entityManager->persist(new PasswordResetToken(
            $user,
            hash('sha256', $token),
            new \DateTimeImmutable(self::VALID_FOR),
        ));
        $this->entityManager->flush();

        $this->deliver($user, $token);
    }

    public function findUsableToken(string $token): ?PasswordResetToken
    {
        $record = $this->tokens->findOneBy(['tokenHash' => hash('sha256', $token)]);

        return $record?->isUsable() === true ? $record : null;
    }

    public function reset(PasswordResetToken $record, string $password): void
    {
        $user = $record->getUser();
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $record->markUsed();

        $this->entityManager->flush();
    }

    private function deliver(User $user, string $token): void
    {
        if (!$this->mailer->isConfigured()) {
            $this->logger->warning('Password reset requested but no mail account is configured.');

            return;
        }

        $link = rtrim($this->publicUrl, '/').'/app/reset-password/'.$token;

        try {
            $this->mailer->send(
                new Email()
                    ->to($user->getEmail())
                    ->subject('Reset your ZomboidControl password')
                    ->text(sprintf(
                        "A password reset was requested for your account.\n\n%s\n\n"
                        ."The link expires in one hour. If this was not you, nothing has changed "
                        ."and you can ignore this message.\n",
                        $link,
                    )),
            );
        } catch (\Throwable $exception) {
            $this->logger->error('Password reset mail could not be sent.', ['exception' => $exception]);
        }
    }
}
