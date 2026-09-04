<?php

declare(strict_types=1);

namespace App\Server\Rcon;

use App\Entity\RconConfig;
use Psr\Log\LoggerInterface;
use xPaw\SourceQuery\Exception\AuthenticationException;
use xPaw\SourceQuery\Exception\InvalidPacketException;
use xPaw\SourceQuery\Exception\SocketException;
use xPaw\SourceQuery\Exception\TimeoutException;
use xPaw\SourceQuery\SourceQuery;

/**
 * Project Zomboid speaks standard Source RCON over TCP.
 *
 * Replies are plain text with no structure, so callers parse them rather
 * than decode them; status data belongs in the file bridge instead.
 */
final readonly class SourceRconClient implements RconClientInterface
{
    private const TIMEOUT_SECONDS = 5;

    /**
     * The library's timeout bounds individual reads, not the exchange as a
     * whole, and a port that accepts a connection without ever answering
     * would otherwise hold the worker indefinitely.
     */
    private const DEADLINE_SECONDS = 12;

    public function __construct(private LoggerInterface $logger)
    {
    }

    public function execute(RconConfig $config, string $command): string
    {
        $command = trim($command);

        if ($command === '') {
            throw new RconCommandFailed('Refusing to send an empty command.');
        }

        $query = new SourceQuery();
        $previousSocketTimeout = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', (string) self::TIMEOUT_SECONDS);

        $deadline = microtime(true) + self::DEADLINE_SECONDS;

        try {
            $query->Connect($config->getHost(), $config->getPort(), self::TIMEOUT_SECONDS, SourceQuery::SOURCE);
            $query->SetRconPassword($config->getPassword());

            $reply = trim($query->Rcon($command));

            if (microtime(true) > $deadline) {
                throw new RconUnreachable('The server took too long to answer.');
            }

            return $reply;
        } catch (AuthenticationException $exception) {
            throw new RconAuthenticationFailed($exception->getMessage(), previous: $exception);
        } catch (SocketException|TimeoutException|InvalidPacketException $exception) {
            // A port that accepts a connection but answers with nothing —
            // or with something that is not RCON — is an unreachable RCON
            // endpoint, not a failed command.
            $this->logger->info('RCON connection failed.', [
                'host' => $config->getHost(),
                'port' => $config->getPort(),
                'exception' => $exception,
            ]);

            throw new RconUnreachable($exception->getMessage(), previous: $exception);
        } catch (\Throwable $exception) {
            throw new RconCommandFailed($exception->getMessage(), previous: $exception);
        } finally {
            $query->Disconnect();
            ini_set('default_socket_timeout', $previousSocketTimeout === false ? '60' : $previousSocketTimeout);
        }
    }

    public function probe(RconConfig $config): string
    {
        // "players" is read-only and present on every Zomboid server.
        return $this->execute($config, 'players');
    }
}
