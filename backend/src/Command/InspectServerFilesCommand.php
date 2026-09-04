<?php

declare(strict_types=1);

namespace App\Command;

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

#[AsCommand(name: 'app:server:ls', description: 'List a directory on the game server')]
final class InspectServerFilesCommand extends Command
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
            ->addArgument('path', InputArgument::OPTIONAL, 'Directory to list', '')
            ->addOption('filter', null, InputOption::VALUE_REQUIRED, 'Only names containing this', '')
            ->addOption('exists', null, InputOption::VALUE_NONE, 'Only report whether the path is there');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = (string) $input->getArgument('server');
        // find() takes a uuid and throws on anything else, so a name is
        // tried first.
        $server = $this->servers->findOneBy(['name' => $name])
            ?? (Uuid::isValid($name) ? $this->servers->find($name) : null);

        if ($server === null) {
            $io->error(sprintf('No server called "%s".', $name));

            return Command::FAILURE;
        }

        $config = $server->getFtpConfig();

        if ($config === null) {
            $io->error('That server has no file access.');

            return Command::FAILURE;
        }

        $path = (string) $input->getArgument('path');

        // A path to a file rather than a directory just says whether it
        // is there, which is what most of these questions really are.
        if ($input->getOption('exists')) {
            $io->writeln(sprintf(
                '%s %s',
                $this->files->fileExists($config, $path) ? '<info>present</info>' : '<comment>absent</comment>',
                $path,
            ));

            return Command::SUCCESS;
        }

        try {
            $listing = $this->files->listDirectory($config, $path);
            $entries = $listing['entries'] ?? [];
        } catch (StorageException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $filter = (string) $input->getOption('filter');

        foreach ($entries as $entry) {
            $name = (string) ($entry['name'] ?? '?');

            if ($filter !== '' && !str_contains(strtolower($name), strtolower($filter))) {
                continue;
            }

            $io->writeln(sprintf(
                '%s %-40s %s',
                ($entry['type'] ?? '') === 'dir' ? '[d]' : '   ',
                $name,
                isset($entry['size']) ? number_format((int) $entry['size']).' bytes' : '',
            ));
        }

        $io->writeln(sprintf('<comment>%d entries.</comment>', \count($entries)));

        return Command::SUCCESS;
    }
}
