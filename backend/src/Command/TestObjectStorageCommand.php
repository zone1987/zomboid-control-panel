<?php

declare(strict_types=1);

namespace App\Command;

use App\Storage\ObjectStorageFactory;
use App\Storage\ObjectStorageProbe;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:storage:test', description: 'Write, read back and delete an object in the configured bucket')]
final class TestObjectStorageCommand extends Command
{
    /** Enough to get past the store's intermittent refusals. */
    private const DELETE_ATTEMPTS = 6;

    public function __construct(
        private readonly ObjectStorageProbe $probe,
        private readonly ObjectStorageFactory $storage,
        private readonly \App\Server\Map\TileUploader $uploader,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('repeat', null, InputOption::VALUE_REQUIRED, 'Run the round trip this many times', '1')
            ->addOption('list', null, InputOption::VALUE_NONE, 'List what the bucket holds instead of testing')
            ->addOption('clean', null, InputOption::VALUE_NONE, 'Delete leftover probe objects')
            ->addOption('clear-prefix', null, InputOption::VALUE_REQUIRED, 'Delete everything under this prefix')
            ->addOption('etag', null, InputOption::VALUE_NONE, 'Check whether the store returns a usable checksum')
            ->addOption('diagnose', null, InputOption::VALUE_NONE, 'Report exactly how the store refuses');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $repeat = max(1, (int) $input->getOption('repeat'));

        if ($input->getOption('diagnose')) {
            return $this->diagnose($io);
        }

        if ($input->getOption('etag')) {
            $filesystem = $this->storage->create();
            $key = 'probe-etag-'.bin2hex(random_bytes(4)).'.txt';
            $body = 'hello';

            $filesystem->write($key, $body);

            try {
                $sum = $filesystem->checksum($key);
                $io->definitionList(
                    ['etag' => $sum],
                    ['md5 of the body' => md5($body)],
                    ['usable' => $sum === md5($body) ? 'yes' : 'no'],
                );
            } catch (\Throwable $exception) {
                $io->warning('No checksum: '.mb_substr($exception->getMessage(), 0, 200));
            } finally {
                $filesystem->delete($key);
            }

            return Command::SUCCESS;
        }

        $prefix = (string) $input->getOption('clear-prefix');

        if ($prefix !== '') {
            if (!$io->confirm(sprintf('Delete every object under "%s"?', $prefix), false)) {
                return Command::SUCCESS;
            }

            $removed = $this->uploader->clear($prefix, static function (int $done) use ($io): void {
                $io->write(sprintf("\r  %d removed", $done));
            });

            $io->newLine();
            $io->success(sprintf('%d object(s) removed.', $removed));

            return Command::SUCCESS;
        }

        if ($input->getOption('list') || $input->getOption('clean')) {
            return $this->inventory($io, (bool) $input->getOption('clean'));
        }

        if ($repeat > 1) {
            return $this->measure($io, $repeat);
        }

        $result = $this->probe->run();

        if ($result['ok']) {
            $io->success(sprintf('The bucket "%s" can be written to and read back.', $result['bucket'] ?? ''));

            return Command::SUCCESS;
        }

        $io->error($result['error'] ?? 'The store refused the request.');

        if (($result['detail'] ?? null) !== null) {
            $io->writeln($result['detail']);
        }

        return Command::FAILURE;
    }

    /**
     * Records exactly how the store refuses, rather than that it did.
     *
     * A run of writes with every failure's class, status and full
     * message: an intermittent AccessDenied looks the same from the
     * outside whether it is a rate limit, a clock skew, a key still
     * propagating, or something about the request itself.
     */
    private function diagnose(SymfonyStyle $io): int
    {
        $filesystem = $this->storage->create();
        $failures = [];
        $ok = 0;
        $times = [];

        for ($i = 0; $i < 40; ++$i) {
            $key = 'diagnose-'.bin2hex(random_bytes(6)).'.txt';
            $started = microtime(true);

            try {
                $filesystem->write($key, str_repeat('x', 4096));
                $times[] = microtime(true) - $started;
                ++$ok;
                $filesystem->delete($key);
            } catch (\Throwable $exception) {
                $deepest = $exception;

                while ($deepest->getPrevious() !== null) {
                    $deepest = $deepest->getPrevious();
                }

                $failures[] = [
                    'attempt' => $i + 1,
                    'class' => $deepest::class,
                    'message' => mb_substr(trim($deepest->getMessage()), 0, 400),
                ];
            }
        }

        $io->definitionList(
            ['attempts' => 40],
            ['succeeded' => $ok],
            ['failed' => \count($failures)],
            ['median write' => $times === [] ? 'n/a' : sprintf('%.2fs', $this->median($times))],
        );

        if ($failures !== []) {
            $io->section('How it refused');

            foreach (\array_slice($failures, 0, 3) as $failure) {
                $io->writeln(sprintf('<comment>attempt %d — %s</comment>', $failure['attempt'], $failure['class']));
                $io->writeln($failure['message']);
                $io->newLine();
            }

            $io->writeln('Failed on attempts: '.implode(', ', array_column($failures, 'attempt')));
        }

        return Command::SUCCESS;
    }

    /** @param list<float> $values */
    private function median(array $values): float
    {
        sort($values);

        return $values[intdiv(\count($values), 2)];
    }

    /**
     * Lists the bucket, and optionally removes what the probe left.
     *
     * A probe that fails between writing and deleting leaves its object
     * behind, so a bucket accumulates them until something clears up.
     */
    private function inventory(SymfonyStyle $io, bool $clean): int
    {
        try {
            $filesystem = $this->storage->create();
            $entries = $filesystem->listContents('', true)->toArray();
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $removed = 0;
        $kept = [];

        foreach ($entries as $entry) {
            $path = $entry->path();
            $isProbe = str_starts_with(basename($path), 'zomboidcontrol-probe-')
                || \in_array(basename($path), ['fixed-name.txt', 'other.txt'], true);

            if ($clean && $isProbe) {
                if ($this->deleteWithRetries($filesystem, $path)) {
                    ++$removed;

                    continue;
                }

                $kept[] = $path.'  <comment>(could not be removed)</comment>';

                continue;
            }

            $kept[] = $path.($isProbe ? '  <comment>(probe leftover)</comment>' : '');
        }

        foreach ($kept as $line) {
            $io->writeln('  '.$line);
        }

        $io->writeln(sprintf(
            '<info>%d object(s) left in the bucket</info>%s',
            \count($kept),
            $clean ? sprintf(', %d removed', $removed) : '',
        ));

        return Command::SUCCESS;
    }

    /**
     * Deletes an object, retrying an intermittent refusal.
     *
     * The store answers AccessDenied to a fraction of requests with a
     * working key, so a single attempt leaves objects behind that the
     * next one removes.
     */
    private function deleteWithRetries(\League\Flysystem\FilesystemOperator $filesystem, string $path): bool
    {
        for ($attempt = 0; $attempt < self::DELETE_ATTEMPTS; ++$attempt) {
            try {
                $filesystem->delete($path);

                return true;
            } catch (\Throwable) {
                usleep(200_000);
            }
        }

        return false;
    }

    /**
     * Reports which step fails and how often, for a store that answers
     * inconsistently rather than always or never.
     */
    private function measure(SymfonyStyle $io, int $repeat): int
    {
        $tally = [];

        for ($i = 0; $i < $repeat; ++$i) {
            $key = 'zomboidcontrol-probe-'.bin2hex(random_bytes(6)).'.txt';
            $step = 'write';

            $filesystem = null;
            $written = false;

            try {
                $filesystem = $this->storage->create();
                $filesystem->write($key, 'probe');
                $written = true;
                $step = 'read';
                $filesystem->read($key);
                $step = 'delete';
                $filesystem->delete($key);
                $written = false;
                $step = 'ok';
            } catch (\Throwable $exception) {
                if ($filesystem !== null && $written) {
                    $this->deleteWithRetries($filesystem, $key);
                }

                $deepest = $exception;

                while ($deepest->getPrevious() !== null) {
                    $deepest = $deepest->getPrevious();
                }

                $step .= ' failed: '.mb_substr(trim($deepest->getMessage()), 0, 700);
            }

            $tally[$step] = ($tally[$step] ?? 0) + 1;
        }

        foreach ($tally as $step => $count) {
            $io->writeln(sprintf('%3d x %s', $count, $step));
        }

        return ($tally['ok'] ?? 0) === $repeat ? Command::SUCCESS : Command::FAILURE;
    }
}
