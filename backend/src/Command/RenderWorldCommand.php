<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\GameServerRepository;
use App\Server\Map\CellFetcher;
use App\Server\Map\TileRenderer;
use App\Server\Map\TileUploader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Renders the whole world and puts it in the object store.
 *
 * Hours of work, so it resumes: cells already rendered are skipped and
 * tiles already uploaded are left alone. Interrupting it costs the
 * batch in flight, nothing more.
 */
#[AsCommand(name: 'app:map:world', description: 'Render every cell of the world and upload the tiles')]
final class RenderWorldCommand extends Command
{
    /** Cells per render call: enough to keep 14 cores busy, small enough to resume. */
    private const BATCH = 12;

    public function __construct(
        private readonly GameServerRepository $servers,
        private readonly TileRenderer $renderer,
        private readonly CellFetcher $cells,
        private readonly TileUploader $uploader,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('server', InputArgument::REQUIRED, 'Server id or name')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'First cell column', '0')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Last cell column', '77')
            ->addOption('rows', null, InputOption::VALUE_REQUIRED, 'Last cell row', '63')
            ->addOption('upload', null, InputOption::VALUE_NONE, 'Push the tiles to the object store')
            ->addOption('upload-only', null, InputOption::VALUE_NONE, 'Skip rendering and only upload what is there')
            ->addOption('tidy', null, InputOption::VALUE_NONE, 'Remove local tiles the store already has')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report the plan and stop');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->renderer->isAvailable()) {
            $io->error('The renderer or the texture packs are missing; see app:map:render --status.');

            return Command::FAILURE;
        }

        if (!$this->renderer->texturesUnpacked()) {
            $io->error('The texture packs have not been unpacked yet: app:map:render <server> --unpack');

            return Command::FAILURE;
        }

        $name = (string) $input->getArgument('server');
        $server = $this->servers->findOneBy(['name' => $name])
            ?? (Uuid::isValid($name) ? $this->servers->find($name) : null);

        if ($server === null) {
            $io->error(sprintf('No server called "%s".', $name));

            return Command::FAILURE;
        }

        if ($input->getOption('upload-only')) {
            return $this->push($io);
        }

        if ($input->getOption('tidy')) {
            return $this->tidy($io);
        }

        $from = (int) $input->getOption('from');
        $to = (int) $input->getOption('to');
        $rows = (int) $input->getOption('rows');

        $cells = [];

        for ($x = $from; $x <= $to; ++$x) {
            for ($y = 0; $y <= $rows; ++$y) {
                $cells[] = [$x, $y];
            }
        }

        $io->writeln(sprintf(
            '%d cells to consider, in batches of %d.',
            \count($cells),
            self::BATCH,
        ));

        if ($input->getOption('dry-run')) {
            return Command::SUCCESS;
        }

        $started = microtime(true);
        $streaming = (bool) $input->getOption('upload');
        $tiles = $this->renderer->tilesDirectory();
        $rendered = 0;
        $absent = 0;
        $uploaded = 0;
        $failed = 0;
        $bytes = 0;
        $progress = $io->createProgressBar(\count($cells));
        $progress->setFormat(" %current%/%max% [%bar%] %percent:3s%%  %elapsed:6s%/%estimated:-6s%  %message%");
        $progress->setMessage('starting');
        $progress->start();

        foreach (array_chunk($cells, self::BATCH) as $batch) {
            $wanted = [];

            foreach ($batch as $cell) {
                if ($this->cells->fetch($server, $cell[0], $cell[1])) {
                    $wanted[] = $cell;
                } else {
                    ++$absent;
                }
            }

            if ($wanted !== [] && $this->renderer->render($server, $wanted)) {
                $rendered += \count($wanted);

                // Straight out again: a full render is 330 GB, which
                // must never accumulate on the panel's own disk. The
                // bucket is the only place it is allowed to exist.
                if ($streaming) {
                    $result = $this->uploader->upload($tiles, 'map/base');
                    $uploaded += $result['sent'];
                    $bytes += $result['bytes'];

                    // Verified before deleting, never after: a tile
                    // that only half arrived is gone for good once the
                    // local copy goes, and this store refuses a
                    // fraction of requests with a working key.
                    $missing = $this->uploader->verify($tiles, 'map/base');

                    if ($missing === []) {
                        $this->clear($tiles);
                    } else {
                        $failed += \count($missing);
                        $io->newLine();
                        $io->warning(sprintf(
                            '%d tile(s) did not arrive; keeping this batch on disk.',
                            \count($missing),
                        ));
                    }
                }
            }

            // Cells are 1 MB each and only feed the renderer; keeping
            // 4.2 GB of them serves nothing once they are drawn.
            $this->clear($this->cells->directory());

            $progress->setMessage($streaming
                ? sprintf('%d rendered, %d uploaded, %d failed', $rendered, $uploaded, $failed)
                : sprintf('%d rendered, %d off the map', $rendered, $absent));
            $progress->advance(\count($batch));
        }

        $progress->finish();
        $io->newLine(2);
        $io->success(sprintf(
            '%d cells rendered in %s.',
            $rendered,
            $this->duration(microtime(true) - $started),
        ));

        if (!$streaming) {
            return Command::SUCCESS;
        }

        $io->writeln(sprintf(
            '  %d tiles uploaded, %d failed, %.1f GB. Nothing kept locally.',
            $uploaded,
            $failed,
            $bytes / 1073741824,
        ));

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    private function push(SymfonyStyle $io): int
    {
        $io->section('Uploading tiles');
        $started = microtime(true);

        $result = $this->uploader->upload(
            $this->renderer->tilesDirectory(),
            'map/base',
            static function (string $key, int $sent, int $failed) use ($io): void {
                $io->write(sprintf("\r  %d sent, %d failed  %-40s", $sent, $failed, basename($key)));
            },
        );

        $io->newLine();
        $io->writeln(sprintf(
            '  %d sent, %d already there, %d failed, %.1f GB in %s.',
            $result['sent'],
            $result['skipped'],
            $result['failed'],
            $result['bytes'] / 1073741824,
            $this->duration(microtime(true) - $started),
        ));

        return $result['failed'] === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Empties a directory, leaving the directory itself.
     *
     * The .dzi descriptors stay: they are tiny, the viewer needs them,
     * and rewriting them on every batch would be wasted work.
     */
    private function clear(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }

            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
            } elseif (!str_ends_with($entry->getFilename(), '.dzi')) {
                @unlink($entry->getPathname());
            }
        }
    }

    /**
     * Removes local tiles the store already holds.
     *
     * What an interrupted run leaves behind: verified before deleting,
     * because a tile only half uploaded is gone for good once the
     * local copy goes.
     */
    private function tidy(SymfonyStyle $io): int
    {
        $tiles = $this->renderer->tilesDirectory();
        $io->writeln('Checking what the store already has …');

        $missing = $this->uploader->verify($tiles, 'map/base');

        if ($missing !== []) {
            $io->warning(sprintf(
                '%d tile(s) are not in the store; nothing removed. Upload them first.',
                \count($missing),
            ));

            foreach (\array_slice($missing, 0, 5) as $key) {
                $io->writeln('  '.$key);
            }

            return Command::FAILURE;
        }

        $removed = 0;
        $freed = 0;

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($tiles, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }

            if ($entry->isDir()) {
                @rmdir($entry->getPathname());

                continue;
            }

            if (str_ends_with($entry->getFilename(), '.dzi')) {
                continue;
            }

            $freed += $entry->getSize();
            @unlink($entry->getPathname());
            ++$removed;
        }

        $io->success(sprintf('%d tile(s) removed, %.0f MB freed.', $removed, $freed / 1048576));

        return Command::SUCCESS;
    }

    private function duration(float $seconds): string
    {
        return $seconds < 90
            ? sprintf('%.1fs', $seconds)
            : sprintf('%dm %ds', (int) ($seconds / 60), (int) $seconds % 60);
    }
}
