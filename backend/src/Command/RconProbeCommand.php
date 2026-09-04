<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\GameServer;
use App\Repository\GameServerRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Reports how a server answers one command at the RCON packet level.
 * Written for the case a reply arrives split across packets, which the
 * ordinary client cannot always reassemble.
 */
#[AsCommand(name: 'app:rcon:probe', description: 'Inspect the raw RCON packets a command produces')]
final class RconProbeCommand extends Command
{
    private const AUTH = 3;
    private const EXEC = 2;
    private const RESPONSE = 0;

    public function __construct(private readonly GameServerRepository $servers)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('server', InputArgument::REQUIRED, 'Server id or name')
            ->addArgument('line', InputArgument::REQUIRED, 'The command to send');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $server = $this->resolve((string) $input->getArgument('server'));
        $config = $server?->getRconConfig();

        if ($config === null) {
            $io->error('No matching server, or no RCON configuration.');

            return Command::FAILURE;
        }

        $socket = @fsockopen($config->getHost(), $config->getPort(), $errno, $error, 5);

        if ($socket === false) {
            $io->error(sprintf('Could not connect: %s', $error));

            return Command::FAILURE;
        }

        stream_set_timeout($socket, 5);

        try {
            $this->send($socket, 1, self::AUTH, $config->getPassword());
            $reply = $this->receive($socket);

            // Some servers answer the auth request with an empty value
            // packet before the authentication result itself.
            if ($reply !== null && $reply['type'] === self::RESPONSE) {
                $reply = $this->receive($socket);
            }

            if ($reply === null || $reply['id'] === -1) {
                $io->error('Authentication was refused.');

                return Command::FAILURE;
            }

            $this->send($socket, 2, self::EXEC, (string) $input->getArgument('line'));

            $total = 0;

            for ($index = 0; $index < 20; ++$index) {
                $packet = $this->receive($socket);

                if ($packet === null) {
                    $io->writeln(sprintf('packet %d: nothing more', $index));

                    break;
                }

                $length = \strlen($packet['body']);
                $total += $length;

                $io->writeln(sprintf(
                    'packet %d: declared=%d id=%d type=%d body=%d',
                    $index,
                    $packet['size'],
                    $packet['id'],
                    $packet['type'],
                    $length,
                ));

                if ($index === 0) {
                    $io->section('First 600 bytes');
                    $io->writeln(substr($packet['body'], 0, 600));
                }

                if ($length < 3000) {
                    break;
                }
            }

            $io->success(sprintf('%d bytes in total.', $total));
        } finally {
            fclose($socket);
        }

        return Command::SUCCESS;
    }

    /** @param resource $socket */
    private function send($socket, int $id, int $type, string $body): void
    {
        $payload = pack('VV', $id, $type).$body."\x00\x00";
        fwrite($socket, pack('V', \strlen($payload)).$payload);
    }

    /**
     * @param resource $socket
     *
     * @return array{size: int, id: int, type: int, body: string}|null
     */
    private function receive($socket): ?array
    {
        $head = fread($socket, 4);

        if ($head === false || \strlen($head) < 4) {
            return null;
        }

        $size = unpack('V', $head)[1];
        $data = '';

        while (\strlen($data) < $size) {
            $chunk = fread($socket, $size - \strlen($data));

            if ($chunk === false || $chunk === '') {
                break;
            }

            $data .= $chunk;
        }

        return [
            'size' => $size,
            'id' => unpack('l', substr($data, 0, 4))[1],
            'type' => unpack('l', substr($data, 4, 4))[1],
            'body' => substr($data, 8, -2),
        ];
    }

    private function resolve(string $needle): ?GameServer
    {
        $byId = Uuid::isValid($needle) ? $this->servers->find($needle) : null;

        return $byId ?? $this->servers->findOneBy(['name' => $needle]);
    }
}
