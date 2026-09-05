<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\GameServerRepository;
use App\Server\Storage\FileBrowserInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Throwaway: walks the whole server looking for texture packs. */
#[AsCommand(name: 'app:server:find', description: 'Search the game server for files matching a pattern')]
final class FindPacksCommand extends Command
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
            ->addArgument('server', InputArgument::REQUIRED)
            ->addArgument('pattern', InputArgument::REQUIRED, 'Substring to look for, case-insensitive')
            ->addOption('root', null, InputOption::VALUE_REQUIRED, 'Where to start', '')
            ->addOption('depth', null, InputOption::VALUE_REQUIRED, 'How deep to walk', '4');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $server = $this->servers->findOneBy(['name' => (string) $input->getArgument('server')]);
        $config = $server?->getFtpConfig();

        if ($config === null) {
            $io->error('No such server, or it has no file access.');

            return Command::FAILURE;
        }

        $pattern = mb_strtolower((string) $input->getArgument('pattern'));
        $maxDepth = (int) $input->getOption('depth');
        $queue = [[(string) $input->getOption('root'), 0]];
        $hits = 0;
        $visited = 0;

        while ($queue !== []) {
            [$path, $depth] = array_shift($queue);

            try {
                $entries = $this->files->listDirectory($config, $path)['entries'] ?? [];
            } catch (\Throwable) {
                continue;
            }

            ++$visited;

            foreach ($entries as $entry) {
                $name = (string) ($entry['name'] ?? '');
                $full = $path === '' ? $name : $path.'/'.$name;
                $isDir = ($entry['type'] ?? '') === 'directory';

                if (str_contains(mb_strtolower($name), $pattern)) {
                    ++$hits;
                    $io->writeln(sprintf(
                        '%s %s %s',
                        $isDir ? '[d]' : '   ',
                        $full,
                        isset($entry['size']) ? number_format((int) $entry['size']).' bytes' : '',
                    ));
                }

                if ($isDir && $depth < $maxDepth) {
                    $queue[] = [$full, $depth + 1];
                }
            }
        }

        $io->writeln(sprintf('<comment>%d hit(s) in %d directories.</comment>', $hits, $visited));

        return Command::SUCCESS;
    }
}
