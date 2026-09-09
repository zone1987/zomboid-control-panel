<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\GameServerRepository;
use App\Server\Config\ConfigFileLocator;
use App\Server\Mods\ModListReader;
use App\Server\Mods\WorkshopClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reads a server's mod list and resolves it against the workshop.
 *
 * Exists to prove the chain against a real server before any of it is
 * wired to a controller: reading over FTP and looking the ids up are
 * the two halves the interface will depend on.
 */
#[AsCommand('app:mods:probe', 'Read a server mod list and resolve it against the workshop')]
final class ModProbeCommand extends Command
{
    public function __construct(
        private readonly GameServerRepository $servers,
        private readonly ConfigFileLocator $locator,
        private readonly ModListReader $reader,
        private readonly WorkshopClient $workshop,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $server = $this->servers->findAll()[0] ?? null;

        if ($server === null || $server->getFtpConfig() === null) {
            $io->error('No server with transfer credentials.');

            return Command::FAILURE;
        }

        $ftp = $server->getFtpConfig();
        $files = $this->locator->locate($ftp);
        $candidates = $files['ini'];

        // More than one is its own state: picking the first would edit
        // a file the operator never meant.
        if (\count($candidates) !== 1 || $files['directory'] === null) {
            $io->error(sprintf(
                'Expected exactly one ini file, found %d in %s.',
                \count($candidates),
                $files['directory'] ?? 'no directory',
            ));

            return Command::FAILURE;
        }

        $path = rtrim($files['directory'], '/').'/'.$candidates[0];

        $io->section('File');
        $io->writeln($path);

        $list = $this->reader->read($ftp, $path);
        $missing = $this->reader->missingKeys($ftp, $path);

        $io->section('Held by the file');
        $io->writeln(sprintf('workshop ids : %d', \count($list->workshopIds)));
        $io->writeln(sprintf('mod ids      : %d', \count($list->modIds)));
        $io->writeln(sprintf('maps         : %s', implode(' | ', $list->maps) ?: '-'));
        $io->writeln(sprintf('missing keys : %s', implode(', ', $missing) ?: 'none'));

        $io->section('Workshop');
        $io->writeln(sprintf('api key      : %s', $this->workshop->hasKey() ? 'present' : 'absent'));

        if ($list->workshopIds !== []) {
            $result = $this->workshop->itemsById($list->workshopIds);
            $io->writeln(sprintf('lookup       : %s, %d resolved', $result->state->value, \count($result->items)));

            foreach ($result->items as $item) {
                $io->writeln(sprintf(
                    '  %-12s %-34s build=%-3s map=%s',
                    $item->workshopId,
                    mb_substr($item->title, 0, 34),
                    $item->declaredBuild() ?? '-',
                    $item->isMap() ? 'yes' : 'no',
                ));
            }
        }

        $io->section('Search filtered by build');

        foreach ([['Build 42'], ['Build 41'], []] as $tags) {
            $probe = $this->workshop->search('fire', $tags, 'trend', 1, 3);
            $io->writeln(sprintf(
                '  tags=%-12s state=%-6s total=%d',
                implode(',', $tags) ?: '(none)',
                $probe->state->value,
                $probe->total,
            ));

            foreach ($probe->items as $found) {
                $io->writeln(sprintf(
                    '      %-40s builds=%s',
                    mb_substr($found->title, 0, 40),
                    implode('/', $found->declaredBuilds()) ?: '-',
                ));
            }
        }

        $search = $this->workshop->search('fire', ['Build 42'], 'trend', 1, 5);

        foreach ($search->items as $item) {
            $io->writeln(sprintf(
                '  %-12s %-38s %s',
                $item->workshopId,
                mb_substr($item->title, 0, 38),
                implode(', ', \array_slice($item->tags, 0, 4)),
            ));
        }

        $io->section('Dependencies (needs the key)');

        foreach (['3795847162', '3787526143', '3789334174', '3789433437', '3791686284'] as $probe) {
            $details = $this->workshop->details($probe);
            $first = $details->first();
            $io->writeln(sprintf(
                '  %-12s state=%-6s deps=%-2d collection=%-3s %s',
                $probe,
                $details->state->value,
                \count($first?->dependencies ?? []),
                $first?->isCollection === true ? 'yes' : 'no',
                mb_substr($first?->title ?? '-', 0, 30),
            ));
        }

        return Command::SUCCESS;
    }
}
