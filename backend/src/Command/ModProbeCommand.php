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
        private readonly \App\Server\Mods\CoverStore $covers,
        private readonly \App\Server\Mods\ModInfoReader $modInfo,
        private readonly \App\Server\Mods\ModManager $mods,
        private readonly \App\Server\Mods\ManifestReader $manifests,
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

        $io->section('Steam manifest');
        $manifest = $this->manifests->read($ftp);
        $io->writeln(sprintf('state: %s, on disk: %.1f MB', $manifest->state, $manifest->sizeOnDisk / 1048576));

        foreach ($manifest->toArray()['items'] as $itemId => $item) {
            $io->writeln(sprintf(
                '  %-12s %7.1f MB  installed=%s  latest=%s  update=%s',
                $itemId,
                $item['size'] / 1048576,
                date('Y-m-d', $item['timeUpdated']),
                $item['latestTimeUpdated'] > 0 ? date('Y-m-d', $item['latestTimeUpdated']) : '-',
                $item['hasUpdate'] === null ? 'unknown' : ($item['hasUpdate'] ? 'YES' : 'no'),
            ));
        }

        $io->section('Game version from the bridge');
        $reading = $this->mods->build($server);
        $io->writeln(sprintf('build:       %s', $reading->build->number ?? '(unknown)'));
        $io->writeln(sprintf('source:      %s', $reading->source()));
        $io->writeln(sprintf('reported:    %s', $reading->reported ?? '-'));
        $io->writeln(sprintf('entered:     %s', $reading->entered ?? '-'));
        $io->writeln(sprintf('full:        %s', $reading->fullVersion ?? '-'));
        $io->writeln(sprintf('disagrees:   %s', $reading->disagrees() ? 'yes' : 'no'));

        $io->section('Diagnosis');
        $d = $this->mods->diagnose($server);
        $io->writeln(sprintf('state:                %s', $d['state']));
        $io->writeln(sprintf('missing dependencies: [%s]', implode(', ', $d['missingDependencies'])));
        $io->writeln(sprintf('orphaned mod ids:     [%s]', implode(', ', $d['orphanedModIds'])));
        $io->writeln(sprintf('unmapped workshop:    [%s]', implode(', ', $d['unmappedWorkshopIds'])));
        $io->writeln(sprintf('updates available:    [%s]', implode(', ', $d['updates'])));
        $io->writeln(sprintf('left over on disk:    [%s]', implode(', ', $d['leftOver'])));
        $io->writeln(sprintf('load order:           %s changed=%s [%s]',
            $d['loadOrder']['state'],
            $d['loadOrder']['changed'] ? 'yes' : 'no',
            implode(', ', $d['loadOrder']['order']),
        ));

        foreach ($d['modIds'] as $workshopId => $verdict) {
            $io->writeln(sprintf('  %-12s %-16s [%s]', $workshopId, $verdict['state'], implode(', ', $verdict['ids'])));
        }

        $io->section('Mod ids from mod.info');

        foreach (['2875848298', '3770149036', '999999999'] as $probe) {
            $verdict = $this->modInfo->read($ftp, $probe);
            $io->writeln(sprintf(
                '  %-12s state=%-18s ids=[%s] versionMin=%s',
                $probe,
                $verdict->state,
                implode(', ', $verdict->ids),
                $verdict->versionMin ?? '-',
            ));

            foreach ($verdict->paths as $path) {
                $io->writeln('       '.$path);
            }
        }

        $io->section('Raw ini values');
        $raw = $this->reader->read($ftp, $path);
        $io->writeln(sprintf('workshopIds: [%s]', implode(', ', $raw->workshopIds)));
        $io->writeln(sprintf('modIds:      [%s]', implode(', ', $raw->modIds)));
        $io->writeln(sprintf('maps:        [%s]', implode(', ', $raw->maps)));

        $io->section('Description via GetDetails');
        $rich = $this->workshop->details('3798399158')->first();
        $io->writeln(sprintf('description length: %d', mb_strlen($rich?->description ?? '')));
        $io->writeln(sprintf('first 80: %s', mb_substr($rich?->description ?? '(empty)', 0, 80)));

        $public = $this->workshop->itemsById(['3798399158'])->first();
        $io->writeln(sprintf('public endpoint length: %d', mb_strlen($public?->description ?? '')));

        $io->section('Covers');
        $probe = $this->workshop->itemsById(['3795847162', '3789551122', '3794362162']);

        foreach ($probe->items as $found) {
            $before = microtime(true);
            $stored = $this->covers->fetch($found);
            $path = $this->covers->pathFor($found->workshopId);

            $io->writeln(sprintf(
                '  %-12s stored=%-3s %7s bytes  %4.0f ms  %s',
                $found->workshopId,
                $stored ? 'yes' : 'no',
                $stored && $path !== null ? number_format((int) filesize($path)) : '-',
                (microtime(true) - $before) * 1000,
                mb_substr($found->title, 0, 28),
            ));
        }

        return Command::SUCCESS;
    }
}
