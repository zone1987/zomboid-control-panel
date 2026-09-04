<?php

declare(strict_types=1);

namespace App\Command;

use App\Server\Players\ExpiredBanLifter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:bans:lift-expired', description: 'Lift temporary bans whose time is up')]
final class LiftExpiredBansCommand extends Command
{
    public function __construct(private readonly ExpiredBanLifter $lifter)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->lifter->run();
        $io = new SymfonyStyle($input, $output);

        if ($result['lifted'] === 0 && $result['failed'] === 0) {
            $io->writeln('Nothing to lift.');
        } else {
            $io->success(sprintf('Lifted %d ban(s); %d could not be reached.', $result['lifted'], $result['failed']));
        }

        return Command::SUCCESS;
    }
}
