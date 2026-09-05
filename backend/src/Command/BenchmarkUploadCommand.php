<?php

declare(strict_types=1);

namespace App\Command;

use App\Server\Map\TileUploader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:storage:benchmark', description: 'Time an upload of local tiles to the store')]
final class BenchmarkUploadCommand extends Command
{
    public function __construct(
        private readonly TileUploader $uploader,
        private readonly \App\Server\Map\TileRenderer $renderer,
        private readonly \App\Storage\ObjectStorageInterface $storage,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('prefix', null, InputOption::VALUE_REQUIRED, 'Where to write', 'benchmark')
            ->addOption('raw', null, InputOption::VALUE_REQUIRED, 'Time N raw puts at this concurrency');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tiles = $this->renderer->tilesDirectory();
        $prefix = (string) $input->getOption('prefix');

        $concurrency = (int) $input->getOption('raw');

        if ($concurrency > 0) {
            return $this->raw($io, $prefix, $concurrency);
        }

        $started = microtime(true);
        $result = $this->uploader->upload($tiles, $prefix);
        $upload = microtime(true) - $started;

        $started = microtime(true);
        $missing = $this->uploader->verify($tiles, $prefix, $result['inStore']);
        $verify = microtime(true) - $started;

        $io->definitionList(
            ['tiles sent' => $result['sent']],
            ['already there' => $result['skipped']],
            ['failed' => $result['failed']],
            ['upload' => sprintf('%.1fs', $upload)],
            ['verify' => sprintf('%.2fs', $verify)],
            ['missing after verify' => \count($missing)],
            ['throughput', $result['sent'] > 0
                ? sprintf('%.1f tiles/s, %.1f MB/s', $result['sent'] / $upload, $result['bytes'] / $upload / 1048576)
                : 'nothing sent'],
        );

        $this->uploader->clear($prefix);

        return Command::SUCCESS;
    }

    /** Times raw puts, to tell a slow store from a slow caller. */
    private function raw(SymfonyStyle $io, string $prefix, int $concurrency): int
    {
        $client = $this->storage->client();
        $bucket = (string) $this->storage->bucket();
        $body = str_repeat('x', 200_000);
        $count = 96;

        // Started all at once, then awaited: if this is much faster than
        // the batched loop, the client is not the limit -- the way it is
        // driven is.
        $started = microtime(true);
        $flight = [];

        for ($i = 0; $i < $count; ++$i) {
            $flight[] = $client->putObject([
                'Bucket' => $bucket,
                'Key' => sprintf('%s/raw-%d.bin', $prefix, $i),
                'Body' => $body,
            ]);

            if (\count($flight) >= $concurrency) {
                foreach ($flight as $request) {
                    $request->resolve();
                }

                $flight = [];
            }
        }

        foreach ($flight as $request) {
            $request->resolve();
        }

        $elapsed = microtime(true) - $started;

        // The same objects again, awaited only at the very end: if this
        // is much faster, the batching is what serialises them.
        $allAtOnce = microtime(true);
        $pending = [];

        for ($i = 0; $i < $count; ++$i) {
            $pending[] = $client->putObject([
                'Bucket' => $bucket,
                'Key' => sprintf('%s/burst-%d.bin', $prefix, $i),
                'Body' => $body,
            ]);
        }

        foreach ($pending as $request) {
            $request->resolve();
        }

        $burst = microtime(true) - $allAtOnce;

        $io->definitionList(
            ['all at once' => sprintf('%.1fs -> %.1f/s', $burst, $count / $burst)],
        );

        $io->definitionList(
            ['concurrency' => $concurrency],
            ['objects' => $count],
            ['seconds' => sprintf('%.1f', $elapsed)],
            ['per second' => sprintf('%.1f', $count / $elapsed)],
        );

        $this->uploader->clear($prefix);

        return Command::SUCCESS;
    }
}
