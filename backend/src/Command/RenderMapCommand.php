<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\GameServerRepository;
use App\Server\Map\CellFetcher;
use App\Server\Map\TileRenderer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(name: 'app:map:render', description: 'Render isometric map cells from a game server')]
final class RenderMapCommand extends Command
{
    public function __construct(
        private readonly GameServerRepository $servers,
        private readonly TileRenderer $renderer,
        private readonly CellFetcher $cells,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('server', InputArgument::REQUIRED, 'Server id or name')
            ->addArgument('cells', InputArgument::IS_ARRAY, 'Cells as x,y')
            ->addOption('unpack', null, InputOption::VALUE_NONE, 'Cut the texture packs apart first')
            ->addOption('status', null, InputOption::VALUE_NONE, 'Report what is ready and stop');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $readiness = $this->renderer->readiness();

        if ($input->getOption('status')) {
            $io->definitionList(
                ['renderer' => $readiness['renderer'] ? 'present' : 'missing'],
                ['texture packs' => $readiness['textures'] ? 'complete' : implode(', ', $readiness['missingPacks'])],
                ['textures unpacked' => $this->renderer->texturesUnpacked() ? 'yes' : 'no'],
                ['map cells held' => number_format($this->cells->bytesHeld() / 1048576, 1).' MB'],
            );

            return Command::SUCCESS;
        }

        if (!$readiness['ready']) {
            $io->error($readiness['renderer']
                ? 'Texture packs are missing: '.implode(', ', $readiness['missingPacks'])
                : 'The renderer is not installed.');

            return Command::FAILURE;
        }

        if ($input->getOption('unpack')) {
            $io->writeln('Cutting the texture packs apart; this takes a few minutes.');

            if (!$this->renderer->unpackTextures()) {
                $io->error('Unpacking failed.');

                return Command::FAILURE;
            }

            $io->success('Textures unpacked.');
        }

        $name = (string) $input->getArgument('server');
        $server = $this->servers->findOneBy(['name' => $name])
            ?? (Uuid::isValid($name) ? $this->servers->find($name) : null);

        if ($server === null) {
            $io->error(sprintf('No server called "%s".', $name));

            return Command::FAILURE;
        }

        $cells = [];

        foreach ($input->getArgument('cells') as $pair) {
            [$x, $y] = array_pad(explode(',', (string) $pair), 2, null);
            $cells[] = [(int) $x, (int) $y];
        }

        if ($cells === []) {
            $io->warning('No cells given.');

            return Command::SUCCESS;
        }

        $started = microtime(true);
        $rendered = $this->renderer->render($server, $cells);
        $seconds = microtime(true) - $started;

        if (!$rendered) {
            $io->error(sprintf('Nothing was produced (%.1fs).', $seconds));

            return Command::FAILURE;
        }

        $io->success(sprintf('%d cell(s) rendered in %.1fs.', \count($cells), $seconds));

        return Command::SUCCESS;
    }
}
