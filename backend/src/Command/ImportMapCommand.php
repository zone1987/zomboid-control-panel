<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\GameServerRepository;
use App\Server\Map\MapTileStore;
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

#[AsCommand(name: 'app:map:import', description: "Take the game's own world map tiles")]
final class ImportMapCommand extends Command
{
    public function __construct(
        private readonly MapTileStore $store,
        private readonly GameServerRepository $servers,
        private readonly FileBrowserInterface $files,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'path',
                InputArgument::OPTIONAL,
                'A pyramid.zip, or the media/maps directory holding one',
            )
            ->addOption(
                'from-server',
                null,
                InputOption::VALUE_REQUIRED,
                'Fetch it from a game server instead, by id or name',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $server = (string) ($input->getOption('from-server') ?? '');
        $temporary = null;

        if ($server !== '') {
            $temporary = $this->fetchFromServer($server, $io);

            if ($temporary === null) {
                return Command::FAILURE;
            }

            $source = $temporary;
        } else {
            $source = $this->locate((string) $input->getArgument('path'));
        }

        if ($source === null) {
            $io->error('No pyramid.zip there. It sits in media/maps/<map>/ of a game or server installation.');

            return Command::FAILURE;
        }

        $archive = new \ZipArchive();

        if ($archive->open($source) !== true) {
            $io->error('That file is not a readable zip archive.');

            return Command::FAILURE;
        }

        $tiles = 0;

        for ($index = 0; $index < $archive->numFiles; ++$index) {
            if (str_ends_with((string) $archive->getNameIndex($index), '.png')) {
                ++$tiles;
            }
        }

        $archive->close();

        if ($tiles === 0) {
            $io->error('The archive holds no tiles.');

            return Command::FAILURE;
        }

        $target = $this->store->archivePath();
        @mkdir(\dirname($target), 0o775, true);

        if (!copy($source, $target)) {
            $io->error('The archive could not be copied into place.');

            return Command::FAILURE;
        }

        if ($temporary !== null) {
            @unlink($temporary);
        }

        $io->success(sprintf(
            '%d tiles in place. The world map is %d by %d squares.',
            $tiles,
            MapTileStore::WORLD_WIDTH,
            MapTileStore::WORLD_HEIGHT,
        ));

        return Command::SUCCESS;
    }

    /**
     * Fetches the pyramid from the game server itself.
     *
     * Better than a local copy: the server's own files are the version
     * it is actually running, and an operator who rents one has no local
     * installation to point at anyway.
     */
    private function fetchFromServer(string $name, SymfonyStyle $io): ?string
    {
        $server = $this->servers->findOneBy(['name' => $name])
            ?? (Uuid::isValid($name) ? $this->servers->find($name) : null);

        if ($server === null) {
            $io->error(sprintf('No server called "%s".', $name));

            return null;
        }

        $config = $server->getFtpConfig();

        if ($config === null) {
            $io->error('That server has no file access configured.');

            return null;
        }

        foreach (self::MAP_CANDIDATES as $path) {
            if (!$this->files->fileExists($config, $path)) {
                continue;
            }

            $target = sys_get_temp_dir().'/'.uniqid('pz-map-', true).'.zip';
            $io->writeln(sprintf('Fetching <info>%s</info>...', $path));

            try {
                $bytes = $this->files->download($config, $path, $target);
            } catch (StorageException $exception) {
                $io->error($exception->getMessage());

                return null;
            }

            $io->writeln(sprintf('  %s downloaded.', self::humanBytes($bytes)));

            return $target;
        }

        $io->error('That server has no map pyramid under media/maps.');

        return null;
    }

    /**
     * Where a pyramid lives on a server.
     *
     * Only the main map carries one; the others are start areas inside
     * it. Listed rather than searched because a listing of that
     * directory runs to thousands of entries.
     */
    private const MAP_CANDIDATES = [
        'media/maps/Muldraugh, KY/pyramid.zip',
    ];

    private static function humanBytes(int $bytes): string
    {
        return $bytes >= 1048576
            ? sprintf('%.1f MB', $bytes / 1048576)
            : sprintf('%d KB', (int) round($bytes / 1024));
    }

    /** Takes either the archive itself or a directory to look in. */
    private function locate(string $path): ?string
    {
        if (is_file($path)) {
            return $path;
        }

        if (!is_dir($path)) {
            return null;
        }

        $direct = $path.'/pyramid.zip';

        if (is_file($direct)) {
            return $direct;
        }

        // Only the main map carries a pyramid; the others are start
        // areas inside it.
        foreach (glob($path.'/*/pyramid.zip') ?: [] as $candidate) {
            return $candidate;
        }

        return null;
    }
}
