<?php

declare(strict_types=1);

namespace App\Command;

use App\Server\Map\TileReader;
use App\Server\Map\TileUploader;
use App\Storage\ObjectStorageFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Empties a prefix in the object store.
 *
 * Changing the render geometry invalidates every tile already up
 * there, and the renderer refuses to write into a directory whose
 * map_info.json disagrees -- so clearing is a routine step before a
 * fresh run, not an emergency measure.
 */
#[AsCommand(name: 'app:storage:clear', description: 'Delete everything under a prefix in the object store')]
final class ClearStorageCommand extends Command
{
    public function __construct(
        private readonly ObjectStorageFactory $storage,
        private readonly TileUploader $uploader,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'prefix',
                InputArgument::OPTIONAL,
                'What to delete, e.g. B42 or B42/base',
                TileReader::PREFIX,
            )
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Delete without asking')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count what would go, delete nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->storage->isConfigured()) {
            $io->error('No object storage is configured.');

            return Command::FAILURE;
        }

        $prefix = trim((string) $input->getArgument('prefix'), '/');

        if ($prefix === '') {
            $io->error('Refusing to clear the whole bucket; name a prefix.');

            return Command::FAILURE;
        }

        $io->section(sprintf('Prefix "%s" in bucket "%s"', $prefix, (string) $this->storage->bucket()));

        $held = $this->count($prefix);

        if ($held === 0) {
            $io->success('Nothing to delete.');

            return Command::SUCCESS;
        }

        $io->writeln(sprintf('%s objects.', number_format($held)));

        if ($input->getOption('dry-run')) {
            $io->note('Dry run; nothing was deleted.');

            return Command::SUCCESS;
        }

        if (!$input->getOption('force') && $input->isInteractive()
            && !$io->confirm(sprintf('Delete all %s objects?', number_format($held)), false)
        ) {
            $io->writeln('Left alone.');

            return Command::SUCCESS;
        }

        $started = microtime(true);
        $progress = $io->createProgressBar($held);
        $progress->start();

        $removed = $this->uploader->clear($prefix, static function (int $done) use ($progress, $held): void {
            $progress->setProgress(min($done, $held));
        });

        $progress->finish();
        $io->newLine(2);

        $elapsed = microtime(true) - $started;
        $io->success(sprintf(
            '%s objects deleted in %s (%s per second).',
            number_format($removed),
            $this->duration($elapsed),
            number_format($elapsed > 0 ? $removed / $elapsed : 0, 0),
        ));

        $left = $this->count($prefix);

        if ($left > 0) {
            $io->warning(sprintf('%s objects are still there.', number_format($left)));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function count(string $prefix): int
    {
        $held = 0;

        foreach ($this->storage->create()->listContents($prefix, true) as $item) {
            if ($item->isFile()) {
                ++$held;
            }
        }

        return $held;
    }

    private function duration(float $seconds): string
    {
        if ($seconds < 60) {
            return sprintf('%.1f s', $seconds);
        }

        return sprintf('%d min %d s', (int) ($seconds / 60), (int) fmod($seconds, 60));
    }
}
