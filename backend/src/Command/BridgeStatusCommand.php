<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\GameServer;
use App\Repository\GameServerRepository;
use App\Server\Bridge\BridgeInstaller;
use App\Server\Players\BridgeStatusReader;
use App\Server\Players\BridgeUnavailable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:bridge:status',
    description: 'Read the bridge file of a server and report what it says',
)]
final class BridgeStatusCommand extends Command
{
    public function __construct(
        private readonly GameServerRepository $servers,
        private readonly BridgeStatusReader $reader,
        private readonly BridgeInstaller $installer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('server', InputArgument::OPTIONAL, 'Server id or name; omit to check every server');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $servers = $this->resolve($input->getArgument('server'));

        if ($servers === []) {
            $io->error('No matching server.');

            return Command::FAILURE;
        }

        $failed = false;

        foreach ($servers as $server) {
            $io->section($server->getName());

            try {
                $status = $this->reader->refresh($server);
            } catch (BridgeUnavailable $exception) {
                $io->error($exception->getMessage());
                $failed = true;

                continue;
            }

            $installed = $this->installer->status($server);
            $shipped = $installed['availableVersion'];

            $io->definitionList(
                ['Players' => (string) $status['playerCount']],
                ['Written' => $status['generatedAt']->format('Y-m-d H:i:s T')],
                ['Reported by the running bridge' => $status['bridgeVersion']],
                ['Read from the installed file' => $installed['installedVersion'] ?? 'not installed'],
                ['Version shipped here' => $shipped],
                ['Up to date' => $installed['upToDate'] ? 'yes' : 'no'],
                ['Stale' => $status['stale'] ? 'yes' : 'no'],
            );

            if ($status['bridgeVersion'] !== $shipped) {
                $io->warning(sprintf(
                    'The server runs %s while this panel ships %s. Upload the bridge again and restart the server.',
                    $status['bridgeVersion'],
                    $shipped,
                ));
            }

            if ($status['stale']) {
                $io->warning('The file has not been written recently. Is the server running?');
            }
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    /** @return list<GameServer> */
    private function resolve(?string $needle): array
    {
        if ($needle === null) {
            return $this->servers->findBy([], ['name' => 'ASC']);
        }

        $byId = \Symfony\Component\Uid\Uuid::isValid($needle) ? $this->servers->find($needle) : null;

        if ($byId instanceof GameServer) {
            return [$byId];
        }

        return $this->servers->findBy(['name' => $needle]);
    }
}
