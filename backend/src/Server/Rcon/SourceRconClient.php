<?php

declare(strict_types=1);

namespace App\Server\Rcon;

use App\Entity\RconConfig;
use Psr\Log\LoggerInterface;

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
     * A port that accepts a connection without ever answering would
     * otherwise hold the worker indefinitely.
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

        $transport = new RconTransport(self::TIMEOUT_SECONDS, self::DEADLINE_SECONDS);

        try {
            $transport->connect($config->getHost(), $config->getPort(), $config->getPassword());

            return $transport->send($command);
        } catch (RconAuthenticationFailed $exception) {
            throw $exception;
        } catch (RconUnreachable $exception) {
            $this->logger->info('RCON connection failed.', [
                'host' => $config->getHost(),
                'port' => $config->getPort(),
                'exception' => $exception,
            ]);

            throw $exception;
        } catch (\Throwable $exception) {
            throw new RconCommandFailed($exception->getMessage(), previous: $exception);
        } finally {
            $transport->disconnect();
        }
    }

    public function probe(RconConfig $config): string
    {
        // "players" is read-only and present on every Zomboid server.
        return $this->execute($config, 'players');
    }
}
