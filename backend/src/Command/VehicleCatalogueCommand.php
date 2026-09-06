<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\GameServer;
use App\Repository\GameServerRepository;
use App\Server\Bridge\BridgeFiles;
use App\Server\Storage\FileBrowserInterface;
use App\Server\Storage\StorageException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Reads the spawnable-vehicle catalogue the bridge writes. */
#[AsCommand(
    name: 'app:vehicles:catalogue',
    description: 'Report the vehicles a server can spawn, as the bridge last wrote them',
)]
final class VehicleCatalogueCommand extends Command
{
    /** The catalogue is large; the tail reader has to be told so. */
    private const MAX_BYTES = 8388608;

    public function __construct(
        private readonly GameServerRepository $servers,
        private readonly FileBrowserInterface $files,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('server', InputArgument::OPTIONAL, 'Server id or name; omit for the first');
        $this->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'How many to list', '20');
        $this->addOption('search', 's', InputOption::VALUE_REQUIRED, 'Only those whose script contains this');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $server = $this->resolve($input->getArgument('server'));

        if (!$server instanceof GameServer) {
            $io->error('No server found.');

            return Command::FAILURE;
        }

        $config = $server->getFtpConfig();

        if ($config === null) {
            $io->error(sprintf('"%s" has no file access configured.', $server->getName()));

            return Command::FAILURE;
        }

        try {
            $raw = $this->files->readTail($config, BridgeFiles::VEHICLE_CATALOGUE, self::MAX_BYTES);
        } catch (StorageException $exception) {
            $io->error($exception->getMessage());
            $io->note('A bridge older than 0.13.0 does not write this file.');

            return Command::FAILURE;
        }

        $payload = json_decode($raw, true);

        if (!\is_array($payload) || !\is_array($payload['vehicles'] ?? null)) {
            $io->error('The catalogue could not be read as JSON.');

            return Command::FAILURE;
        }

        /** @var list<array<string, mixed>> $vehicles */
        $vehicles = $payload['vehicles'];

        $io->definitionList(
            ['Server' => $server->getName()],
            ['Bridge' => (string) ($payload['bridgeVersion'] ?? '?')],
            ['Vehicles' => (string) \count($vehicles)],
            ['With liveries' => (string) \count(array_filter(
                $vehicles,
                static fn (array $v): bool => \count($v['skins'] ?? []) > 0,
            ))],
            ['Total liveries' => (string) array_sum(array_map(
                static fn (array $v): int => \count($v['skins'] ?? []),
                $vehicles,
            ))],
        );

        $needle = $input->getOption('search');
        $rows = [];

        foreach ($vehicles as $vehicle) {
            $script = (string) ($vehicle['script'] ?? '');

            if (\is_string($needle) && $needle !== '' && stripos($script, $needle) === false) {
                continue;
            }

            $rows[] = [
                $script,
                (string) ($vehicle['model'] ?? '—'),
                (string) \count($vehicle['skins'] ?? []),
            ];
        }

        $limit = max(1, (int) $input->getOption('limit'));
        $io->table(['Script', 'Model', 'Liveries'], \array_slice($rows, 0, $limit));

        if (\count($rows) > $limit) {
            $io->comment(sprintf('%d more not shown.', \count($rows) - $limit));
        }

        return Command::SUCCESS;
    }

    private function resolve(?string $needle): ?GameServer
    {
        if ($needle === null) {
            return $this->servers->findBy([], ['name' => 'ASC'])[0] ?? null;
        }

        return $this->servers->find($needle) ?? $this->servers->findOneBy(['name' => $needle]);
    }
}
