<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\OAuthIdentity;
use App\Entity\User;
use App\Entity\WebauthnCredential;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Moves passkeys and linked identities from one account to another, then
 * removes the source. Used when an address changes: deleting the old
 * account outright would take its sign-in methods with it.
 */
#[AsCommand(name: 'app:user:merge', description: 'Move sign-in methods to another account and delete the source')]
final class MergeUserCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('from', InputArgument::REQUIRED, 'Email of the account to empty and remove')
            ->addArgument('to', InputArgument::REQUIRED, 'Email of the account that keeps everything')
            ->addOption('keep-source', null, InputOption::VALUE_NONE, 'Move everything but leave the source account in place');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $source = $this->users->findOneBy(['email' => $input->getArgument('from')]);
        $target = $this->users->findOneBy(['email' => $input->getArgument('to')]);

        if (!$source instanceof User) {
            $io->error(sprintf('No account found for "%s".', $input->getArgument('from')));

            return Command::FAILURE;
        }

        if (!$target instanceof User) {
            $io->error(sprintf('No account found for "%s".', $input->getArgument('to')));

            return Command::FAILURE;
        }

        if ($source->getId()->equals($target->getId())) {
            $io->error('Source and target are the same account.');

            return Command::FAILURE;
        }

        $moved = ['passkeys' => 0, 'identities' => 0, 'skipped' => 0];

        foreach ($this->entityManager->getRepository(WebauthnCredential::class)->findBy(['user' => $source]) as $credential) {
            $this->reassign($credential, 'user', $target);
            ++$moved['passkeys'];
        }

        foreach ($this->entityManager->getRepository(OAuthIdentity::class)->findBy(['user' => $source]) as $identity) {
            $existing = $this->entityManager->getRepository(OAuthIdentity::class)
                ->findOneBy(['user' => $target, 'provider' => $identity->getProvider()]);

            if ($existing instanceof OAuthIdentity) {
                // The target already signs in with this provider; keeping
                // both would violate the one-per-provider assumption.
                $this->entityManager->remove($identity);
                ++$moved['skipped'];

                continue;
            }

            $this->reassign($identity, 'user', $target);
            ++$moved['identities'];
        }

        $this->entityManager->flush();

        if (!$input->getOption('keep-source')) {
            $this->entityManager->remove($source);
            $this->entityManager->flush();
        }

        $io->success(sprintf(
            'Moved %d passkey(s) and %d identity/identities to %s; %d already present and dropped.',
            $moved['passkeys'],
            $moved['identities'],
            $target->getEmail(),
            $moved['skipped'],
        ));

        return Command::SUCCESS;
    }

    private function reassign(object $entity, string $property, User $target): void
    {
        $reflection = new \ReflectionProperty($entity::class, $property);
        $reflection->setValue($entity, $target);
    }
}
