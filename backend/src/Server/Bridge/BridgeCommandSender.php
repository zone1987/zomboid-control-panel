<?php

declare(strict_types=1);

namespace App\Server\Bridge;

use App\Entity\GameServer;
use App\Server\Storage\FileBrowserInterface;
use App\Server\Storage\StorageException;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Asks the running server to do something, and waits for its answer.
 *
 * The queue is deliberately plain: the panel writes cmd-<seq>.json and
 * the bridge answers with res-<seq>.json. Each side owns its own files
 * and its own cursor, and reads the other's only to recover from a
 * drift.
 *
 * The resync is forward-only in both directions. A stale read of the
 * other side's cursor can only ever be lower than the truth, never
 * invented higher, so moving forward to it cannot skip work that was
 * really done -- whereas following it backward would replay commands.
 */
final readonly class BridgeCommandSender
{
    public function __construct(
        private FileBrowserInterface $files,
        private CacheItemPoolInterface $cache,
        /** How long to wait for the bridge before giving up on one command. */
        private float $timeoutSeconds = 12.0,
        /** The bridge reads once a second; polling faster only costs traffic. */
        private int $pollMicroseconds = 700_000,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @throws BridgeCommandFailed
     * @throws InvalidBridgeCommand
     */
    public function send(GameServer $server, BridgeCommand $command, array $arguments = []): BridgeResult
    {
        $config = $server->getFtpConfig();

        if ($config === null) {
            throw new BridgeCommandFailed('This server has no file access.', 'servers.ftpNotConfigured');
        }

        $sequence = $this->claimSequence($server);
        $payload = ['action' => $command->value, ...$command->validate($arguments)];

        try {
            $this->files->upload(
                $config,
                BridgeFiles::commandFile($sequence),
                json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
            );

            // Written after the command it accounts for, so the number in
            // it is always backed by a file that is really there.
            $this->files->upload(
                $config,
                BridgeFiles::PANEL_CURSOR,
                json_encode(['nextCommandSeq' => $sequence + 1], \JSON_THROW_ON_ERROR),
            );
        } catch (StorageException $exception) {
            throw new BridgeCommandFailed($exception->getMessage(), $exception->messageKey());
        }

        return $this->awaitResult($server, $sequence);
    }

    /** @throws BridgeCommandFailed */
    private function awaitResult(GameServer $server, int $sequence): BridgeResult
    {
        $config = $server->getFtpConfig();
        $deadline = microtime(true) + $this->timeoutSeconds;
        $path = BridgeFiles::resultFile($sequence);

        while (microtime(true) < $deadline) {
            try {
                if ($this->files->fileExists($config, $path)) {
                    return BridgeResult::parse($this->files->readTail($config, $path));
                }
            } catch (StorageException) {
                // A read that fails mid-poll is not yet a failure; the
                // deadline decides.
            }

            usleep($this->pollMicroseconds);
        }

        throw new BridgeCommandFailed(
            sprintf('The bridge did not answer command %d within %.0f seconds.', $sequence, $this->timeoutSeconds),
            'bridge.noAnswer',
        );
    }

    /**
     * The next number to use, remembered per server.
     *
     * Kept in the cache rather than the database because it is a
     * position, not a record: losing it costs one resync, and the
     * bridge's own cursor is what the recovery reads.
     */
    private function claimSequence(GameServer $server): int
    {
        $item = $this->cache->getItem($this->key($server));
        $next = $item->isHit() && \is_int($item->get()) ? $item->get() : $this->sequenceFromBridge($server);

        $item->set($next + 1)->expiresAfter(86400);
        $this->cache->save($item);

        return $next;
    }

    /**
     * Where to start when the panel has no memory of this server.
     *
     * Read from the bridge's own cursor: starting at 1 again would make
     * the bridge ignore everything until it caught up to where it
     * already was.
     */
    private function sequenceFromBridge(GameServer $server): int
    {
        $config = $server->getFtpConfig();

        if ($config === null) {
            return 1;
        }

        try {
            $raw = $this->files->readTail($config, BridgeFiles::BRIDGE_CURSOR, 2048);
            $cursor = json_decode($raw, true, 8, \JSON_THROW_ON_ERROR);
        } catch (StorageException|\JsonException) {
            return 1;
        }

        $last = \is_array($cursor) ? ($cursor['lastCommandSeq'] ?? null) : null;

        return \is_int($last) ? $last + 1 : 1;
    }

    public function forget(GameServer $server): void
    {
        $this->cache->deleteItem($this->key($server));
    }

    private function key(GameServer $server): string
    {
        return 'bridge.sequence.'.$server->getId()->toRfc4122();
    }
}
