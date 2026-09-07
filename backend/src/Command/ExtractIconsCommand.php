<?php

declare(strict_types=1);

namespace App\Command;

use App\Server\Items\Icons\IconExtractor;
use App\Server\Items\Icons\IconStore;
use App\Server\Items\Icons\MalformedPack;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:icons:extract', description: 'Cut item icons out of Zomboid texture packs')]
final class ExtractIconsCommand extends Command
{
    public function __construct(
        private readonly IconExtractor $extractor,
        private readonly IconStore $store,
        private readonly IconStore $characterStore,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::REQUIRED, 'A .pack file, or a directory of them')
            ->addOption('clear', null, InputOption::VALUE_NONE, 'Empty the store first')
            ->addOption(
                'characters',
                null,
                InputOption::VALUE_NONE,
                'Cut profession and trait icons instead of item icons',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = (string) $input->getArgument('path');

        $characters = $input->getOption('characters') === true;
        $store = $characters ? $this->characterStore : $this->store;
        $prefixes = $characters ? IconExtractor::CHARACTER_ICONS : IconExtractor::ITEM_ICONS;

        $files = is_dir($path) ? (glob($path.'/*.pack') ?: []) : [$path];

        if ($files === []) {
            $io->error('No .pack file there.');

            return Command::FAILURE;
        }

        if ($input->getOption('clear')) {
            $store->clear();
        }

        $total = 0;

        foreach ($files as $file) {
            $contents = @file_get_contents($file);

            if ($contents === false) {
                $io->warning(sprintf('%s could not be read.', basename($file)));

                continue;
            }

            try {
                $result = $this->extractor->extract($contents, basename($file), $prefixes, $store);
            } catch (MalformedPack $exception) {
                // Tile packs hold no item icons and may use another
                // layout; skipping them is expected, not a failure.
                $io->writeln(sprintf(
                    '  <comment>%s skipped: %s</comment>',
                    basename($file),
                    $exception->getMessage(),
                ));

                continue;
            }

            $total += $result['extracted'];

            $io->writeln(sprintf(
                '  %-28s %4d icons from %2d pages%s',
                basename($file),
                $result['extracted'],
                $result['pages'],
                $result['skipped'] > 0 ? sprintf(' (%d skipped)', $result['skipped']) : '',
            ));
        }

        $io->success(sprintf('%d icons extracted; the store holds %d.', $total, $store->count()));

        return Command::SUCCESS;
    }
}
