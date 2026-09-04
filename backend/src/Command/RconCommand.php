<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\GameServer;
use App\Repository\GameServerRepository;
use App\Server\Rcon\RconException;
use App\Server\Rcon\SourceRconClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(name: 'app:rcon', description: 'Send one RCON command to a server and print the reply')]
final class RconCommand extends Command
{
    public function __construct(
        private readonly GameServerRepository $servers,
        private readonly SourceRconClient $rcon,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('server', InputArgument::REQUIRED, 'Server id or name')
            ->addArgument('line', InputArgument::REQUIRED, 'The command to send')
            ->addArgument('args', InputArgument::IS_ARRAY, 'Arguments');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $server = $this->resolve((string) $input->getArgument('server'));

        if (!$server instanceof GameServer) {
            $io->error('No matching server.');

            return Command::FAILURE;
        }

        $line = trim(implode(' ', [
            (string) $input->getArgument('line'),
            ...$input->getArgument('args'),
        ]));

        $config = $server->getRconConfig();

        if ($config === null) {
            $io->error('This server has no RCON configuration.');

            return Command::FAILURE;
        }

        try {
            $output->writeln($this->rcon->execute($config, $line));
        } catch (RconException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function resolve(string $needle): ?GameServer
    {
        $byId = Uuid::isValid($needle) ? $this->servers->find($needle) : null;

        return $byId ?? $this->servers->findOneBy(['name' => $needle]);
    }
}
