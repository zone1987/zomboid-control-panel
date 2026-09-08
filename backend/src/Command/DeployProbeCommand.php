<?php

declare(strict_types=1);

namespace App\Command;

use App\Panel\DeployProbe;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:deploy:probe',
    description: 'Ask the hosting platform whether a deployment would work, without starting one',
)]
final class DeployProbeCommand extends Command
{
    public function __construct(private readonly DeployProbe $probe)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'deployment',
            InputArgument::OPTIONAL,
            'A deployment uuid to report the progress of instead of probing',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $deployment = $input->getArgument('deployment');

        if (\is_string($deployment) && $deployment !== '') {
            $status = $this->probe->statusOf($deployment);

            $io->definitionList(
                ['state' => $status->state],
                ['reported' => $status->reported ?? '—'],
                ['settled' => $status->isSettled() ? 'yes' : 'no'],
                ['detail' => $status->detail ?? '—'],
            );

            return $status->succeeded() ? Command::SUCCESS : Command::FAILURE;
        }

        $verdict = $this->probe->probe();

        $io->definitionList(
            ['state' => $verdict->state],
            ['http status' => $verdict->status === null ? '—' : (string) $verdict->status],
            ['application' => $verdict->applicationName ?? '—'],
            ['running' => $verdict->applicationState ?? '—'],
            ['detail' => $verdict->detail ?? '—'],
        );

        if ($verdict->looksReady()) {
            $io->success('A deployment would be accepted.');

            return Command::SUCCESS;
        }

        $io->error('A deployment would not work as configured.');

        return Command::FAILURE;
    }
}
