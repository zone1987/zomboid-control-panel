<?php

declare(strict_types=1);

namespace App\Command;

use App\Server\Players\StalePlayerPurger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:players:purge', description: 'Remove player snapshots older than the configured retention')]
final class PurgePlayersCommand extends Command
{
    public function __construct(private readonly StalePlayerPurger $purger)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would go without deleting it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $result = $this->purger->run($dryRun);

        if ($result['cutoff'] === null) {
            $io->writeln('Retention is off; keeping every snapshot. Purged 0.');

            return Command::SUCCESS;
        }

        $io->writeln(sprintf('Cutoff: %s', $result['cutoff']->format('Y-m-d H:i:s')));

        if ($dryRun) {
            $io->writeln(sprintf('Would purge %d snapshot(s). Nothing was deleted.', $result['purged']));

            return Command::SUCCESS;
        }

        $io->success(sprintf('Purged %d snapshot(s).', $result['purged']));

        return Command::SUCCESS;
    }
}
