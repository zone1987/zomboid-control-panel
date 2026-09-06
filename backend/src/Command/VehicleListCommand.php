<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\GameServer;
use App\Repository\GameServerRepository;
use App\Server\Vehicles\VehicleOverlay;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'app:vehicles',
    description: 'List the vehicles a server has, and say which source each came from',
)]
final class VehicleListCommand extends Command
{
    public function __construct(
        private readonly GameServerRepository $servers,
        private readonly VehicleOverlay $vehicles,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('server', InputArgument::REQUIRED, 'Server id or name')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'How many to print', '20')
            ->addOption('damaged', null, InputOption::VALUE_NONE, 'Only the ones that took a hit');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $needle = (string) $input->getArgument('server');
        $server = $this->servers->findOneBy(['name' => $needle])
            ?? (Uuid::isValid($needle) ? $this->servers->find($needle) : null);

        if (!$server instanceof GameServer) {
            $io->error(sprintf('No server called "%s".', $needle));

            return Command::FAILURE;
        }

        $result = $this->vehicles->of($server);
        $items = $result['items'];

        if ($input->getOption('damaged')) {
            $items = array_values(array_filter(
                $items,
                static fn (array $vehicle): bool => ($vehicle['condition']['damaged'] ?? false) === true,
            ));
        }

        $io->definitionList(
            ['Source' => $result['source']],
            ['Vehicles the world has' => (string) \count($result['items'])],
            ['Loaded right now' => (string) $result['loaded']],
        );

        if ($items === []) {
            $io->warning(match ($result['source']) {
                'none' => 'No vehicle database and no bridge reading. Check the transfer credentials.',
                default => 'Nothing matched.',
            });

            return Command::SUCCESS;
        }

        $io->table(
            ['id', 'script', 'x', 'y', 'facing', 'condition', 'paint', 'live'],
            array_map(self::row(...), \array_slice($items, 0, max(1, (int) $input->getOption('limit')))),
        );

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $vehicle
     *
     * @return list<string>
     */
    private static function row(array $vehicle): array
    {
        /** @var array<string, mixed> $condition */
        $condition = \is_array($vehicle['condition'] ?? null) ? $vehicle['condition'] : [];
        $hue = $vehicle['hue'] ?? null;

        return [
            (string) ($vehicle['id'] ?? '?'),
            (string) ($vehicle['script'] ?? '?'),
            (string) ($vehicle['x'] ?? '?'),
            (string) ($vehicle['y'] ?? '?'),
            $vehicle['heading'] === null ? 'unknown' : sprintf('%.0f°', (float) $vehicle['heading']),
            ($condition['damaged'] ?? false) === true
                ? sprintf('%d%% intact', (int) round(100 * (float) ($condition['intact'] ?? 0)))
                : 'sound',
            $hue === null ? '—' : sprintf('h%.2f', (float) $hue),
            isset($vehicle['live']) ? 'yes' : '',
        ];
    }
}
