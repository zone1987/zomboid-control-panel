<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A way in that does not depend on the browser: recovering a locked-out
 * installation, or seeding one during deployment.
 */
#[AsCommand(name: 'app:user:create', description: 'Create a user account or reset its password')]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email address')
            ->addArgument('password', InputArgument::REQUIRED, 'Password, at least 12 characters')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Display name')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Grant administrator roles');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = (string) $input->getArgument('email');
        $password = (string) $input->getArgument('password');

        if (mb_strlen($password) < 12) {
            $io->error('The password must be at least 12 characters long.');

            return Command::FAILURE;
        }

        $user = $this->users->findOneBy(['email' => $email]);
        $existed = $user instanceof User;

        if (!$existed) {
            $user = new User($email, (string) ($input->getOption('name') ?? $email));
        } elseif ($input->getOption('name') !== null) {
            $user->setDisplayName((string) $input->getOption('name'));
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        if ($input->getOption('admin')) {
            $user->setRoles([User::ROLE_ADMIN, User::ROLE_SERVER_ADMIN]);
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(sprintf(
            '%s %s with roles: %s',
            $existed ? 'Updated' : 'Created',
            $email,
            implode(', ', $user->getRoles()),
        ));

        return Command::SUCCESS;
    }
}
