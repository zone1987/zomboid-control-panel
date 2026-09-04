<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\GameServer;
use App\Repository\GameServerRepository;
use App\Server\Storage\FileBrowserInterface;
use App\Server\Storage\StorageException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(name: 'app:fetch', description: 'Download one file from a server over its transfer credentials')]
final class BridgeFetchCommand extends Command
{
    public function __construct(
        private readonly GameServerRepository $servers,
        private readonly FileBrowserInterface $files,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('server', InputArgument::REQUIRED, 'Server id or name')
            ->addArgument('path', InputArgument::REQUIRED, 'Path relative to the base path')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Write here instead of standard output')
            ->addOption('max', null, InputOption::VALUE_REQUIRED, 'Byte ceiling', '8388608');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $needle = (string) $input->getArgument('server');
        $server = Uuid::isValid($needle) ? $this->servers->find($needle) : $this->servers->findOneBy(['name' => $needle]);
        $config = $server instanceof GameServer ? $server->getFtpConfig() : null;

        if ($config === null) {
            $io->error('No matching server, or no transfer configuration.');

            return Command::FAILURE;
        }

        try {
            $contents = $this->files->readTail(
                $config,
                (string) $input->getArgument('path'),
                (int) $input->getOption('max'),
            );
        } catch (StorageException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $target = $input->getOption('to');

        if ($target === null) {
            $output->write($contents);

            return Command::SUCCESS;
        }

        file_put_contents($target, $contents);
        $io->success(sprintf('%d bytes written to %s.', \strlen($contents), $target));

        return Command::SUCCESS;
    }
}
