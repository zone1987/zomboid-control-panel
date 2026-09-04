<?php

declare(strict_types=1);

namespace App\Command;

use App\Server\Map\MapTileStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:map:import', description: "Take the game's own world map tiles")]
final class ImportMapCommand extends Command
{
    public function __construct(private readonly MapTileStore $store)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'path',
            InputArgument::REQUIRED,
            'A pyramid.zip, or the media/maps directory holding one',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $source = $this->locate((string) $input->getArgument('path'));

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

        $io->success(sprintf(
            '%d tiles in place. The world map is %d by %d squares.',
            $tiles,
            MapTileStore::WORLD_WIDTH,
            MapTileStore::WORLD_HEIGHT,
        ));

        return Command::SUCCESS;
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
