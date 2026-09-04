<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\GameServer;
use App\Repository\GameServerRepository;
use App\Server\Logs\LogTailer;
use App\Server\Storage\StorageException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(name: 'app:logs:tail', description: 'Read the end of a log file over SFTP')]
final class LogTailCommand extends Command
{
    public function __construct(
        private readonly GameServerRepository $servers,
        private readonly LogTailer $tailer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('server', InputArgument::REQUIRED, 'Server id or name')
            ->addArgument('path', InputArgument::REQUIRED, 'File path relative to the base path')
            ->addOption('offset', null, InputOption::VALUE_REQUIRED, 'Continue from this byte offset');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $server = $this->resolve((string) $input->getArgument('server'));
        $config = $server?->getFtpConfig();

        if ($config === null) {
            $io->error('No matching server, or no transfer configuration.');

            return Command::FAILURE;
        }

        $offset = $input->getOption('offset');

        try {
            $result = $this->tailer->read(
                $config,
                (string) $input->getArgument('path'),
                $offset === null ? null : (int) $offset,
            );
        } catch (StorageException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        foreach ($result['lines'] as $line) {
            $output->writeln($line);
        }

        $io->newLine();
        $io->comment(sprintf(
            '%d lines, next offset %d%s%s',
            \count($result['lines']),
            $result['offset'],
            $result['truncated'] ? ', truncated' : '',
            $result['rotated'] ? ', file had rotated' : '',
        ));

        return Command::SUCCESS;
    }

    private function resolve(string $needle): ?GameServer
    {
        $byId = Uuid::isValid($needle) ? $this->servers->find($needle) : null;

        return $byId ?? $this->servers->findOneBy(['name' => $needle]);
    }
}
