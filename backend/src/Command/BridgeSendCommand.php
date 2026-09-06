<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\GameServer;
use App\Repository\GameServerRepository;
use App\Server\Bridge\BridgeCommand;
use App\Server\Bridge\BridgeCommandFailed;
use App\Server\Bridge\BridgeCommandSender;
use App\Server\Bridge\InvalidBridgeCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Sends one command to a server's bridge and prints what came back.
 *
 * A command exists so a handler can be exercised before anything is
 * built on it. Five of bridge 0.17.0's handlers shipped without ever
 * being fired, which is precisely the state this exists to end — and
 * when one misbehaves later, this reaches it without a page in between.
 */
#[AsCommand(
    name: 'app:bridge:send',
    description: 'Send one command to a server bridge and print its answer',
)]
final class BridgeSendCommand extends Command
{
    public function __construct(
        private readonly GameServerRepository $servers,
        private readonly BridgeCommandSender $bridge,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('server', InputArgument::REQUIRED, 'Server id or name')
            ->addArgument('action', InputArgument::REQUIRED, sprintf(
                'One of: %s',
                implode(', ', array_map(
                    static fn (BridgeCommand $c): string => $c->value,
                    BridgeCommand::cases(),
                )),
            ))
            ->addOption(
                'arg',
                'a',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'An argument as name=value; repeatable',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $server = $this->resolve((string) $input->getArgument('server'));

        if (!$server instanceof GameServer) {
            $io->error('No matching server.');

            return Command::FAILURE;
        }

        $command = BridgeCommand::tryFrom((string) $input->getArgument('action'));

        if ($command === null) {
            $io->error(sprintf('There is no bridge command called "%s".', $input->getArgument('action')));

            return Command::FAILURE;
        }

        /** @var list<string> $pairs */
        $pairs = $input->getOption('arg');

        try {
            $result = $this->bridge->send($server, $command, self::parse($pairs));
        } catch (InvalidBridgeCommand $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        } catch (BridgeCommandFailed $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->definitionList(
            ['Command' => $command->value],
            ['Accepted' => $result->ok ? 'yes' : 'no'],
            ['Message' => $result->message === '' ? '(none)' : $result->message],
        );

        if ($result->data !== null) {
            $io->writeln(json_encode($result->data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
        }

        return $result->ok ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @param list<string> $pairs
     *
     * @return array<string, string>
     */
    private static function parse(array $pairs): array
    {
        $arguments = [];

        foreach ($pairs as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');

            $arguments[$name] = $value;
        }

        return $arguments;
    }

    private function resolve(string $needle): ?GameServer
    {
        if (Uuid::isValid($needle)) {
            return $this->servers->find($needle);
        }

        return $this->servers->findOneBy(['name' => $needle]);
    }
}
