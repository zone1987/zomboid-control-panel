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
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(name: 'app:logs:list', description: 'List the log files a server has')]
final class LogListCommand extends Command
{
    public function __construct(
        private readonly GameServerRepository $servers,
        private readonly FileBrowserInterface $browser,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('server', InputArgument::REQUIRED, 'Server id or name')
            ->addArgument('path', InputArgument::OPTIONAL, 'Directory to list, relative to the base path');
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

        $path = (string) ($input->getArgument('path') ?? $config->getLogPath() ?? '');

        try {
            $listing = $this->browser->listDirectory($config, $path);
        } catch (StorageException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->section($listing['path']);

        $rows = array_map(
            static fn (array $entry): array => [
                $entry['type'],
                $entry['name'],
                $entry['size'] === null ? '' : number_format($entry['size']),
                $entry['lastModified'] === null ? '' : date('Y-m-d H:i', $entry['lastModified']),
            ],
            $listing['entries'],
        );

        $io->table(['Type', 'Name', 'Bytes', 'Modified'], $rows);

        return Command::SUCCESS;
    }

    private function resolve(string $needle): ?GameServer
    {
        $byId = Uuid::isValid($needle) ? $this->servers->find($needle) : null;

        return $byId ?? $this->servers->findOneBy(['name' => $needle]);
    }
}
