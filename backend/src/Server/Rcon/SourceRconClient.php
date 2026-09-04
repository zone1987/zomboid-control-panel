<?php

declare(strict_types=1);

namespace App\Server\Rcon;

use App\Entity\RconConfig;
use Psr\Log\LoggerInterface;
use xPaw\SourceQuery\Exception\AuthenticationException;
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

        try {
            $query->Connect($config->getHost(), $config->getPort(), self::TIMEOUT_SECONDS, SourceQuery::SOURCE);
            $query->SetRconPassword($config->getPassword());

            return trim($query->Rcon($command));
        } catch (AuthenticationException $exception) {
            throw new RconAuthenticationFailed($exception->getMessage(), previous: $exception);
        } catch (SocketException|TimeoutException $exception) {
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
        }
    }

    public function probe(RconConfig $config): string
    {
        // "players" is read-only and present on every Zomboid server.
        return $this->execute($config, 'players');
    }
}
