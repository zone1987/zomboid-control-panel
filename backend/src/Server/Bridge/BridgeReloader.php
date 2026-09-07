<?php

declare(strict_types=1);

namespace App\Server\Bridge;

use App\Entity\GameServer;
use App\Server\Rcon\RconClientInterface;
use App\Server\Rcon\RconException;
use Psr\Log\LoggerInterface;

/**
 * Loads a freshly uploaded bridge into a running server, and proves it.
 *
 * `reloadlua <file>` re-executes the file from disk. The game's own
 * `LuaEventManager.reroute` replaces the handlers rather than adding to
 * them — `RunLua(path, true)` sets `LuaCompiler.rewriteEvents`, and
 * every closure built during the reload goes through it — so the bridge
 * re-registers correctly on its own. Measured on a live server: three
 * commands moved the queue cursor by exactly three, and the cursor
 * carried over rather than resetting, so nothing was replayed.
 *
 * The reload is only half the job. The reply `Lua file reloaded` says
 * the game found a file, not that the new handler set is answering, so
 * this class reads the version back out of what the *running* bridge
 * writes and returns one of six distinct outcomes. Anything short of a
 * confirmed match means the operator still has to restart.
 */
final readonly class BridgeReloader
{
    /**
     * A reload re-runs the module, which mints a new SESSION_ID and
     * writes server.json at once. Waiting for that is what separates
     * "the game answered" from "the new code is running".
     */
    public const READBACK_ATTEMPTS = 6;

    private const READBACK_DELAY_MICROSECONDS = 500000;

    /** What the game says when it cannot find the file. */
    private const UNKNOWN_FILE = 'Unknown Lua file';

    private const RELOADED = 'reloaded';

    /**
     * @param int $readbackDelayMicroseconds zero in a test: the wait is
     *     there to give a real server time to write, and a suite that
     *     actually sleeps for it takes ten seconds to prove nothing
     */
    public function __construct(
        private RconClientInterface $rcon,
        private RunningBridgeReading $info,
        private LoggerInterface $logger,
        private int $readbackDelayMicroseconds = self::READBACK_DELAY_MICROSECONDS,
    ) {
    }

    /**
     * @param string $expected the version that was just uploaded
     */
    public function reload(GameServer $server, string $expected): BridgeReloadOutcome
    {
        $config = $server->getRconConfig();

        if ($config === null) {
            return BridgeReloadOutcome::NoRcon;
        }

        $before = $this->sessionId($server);

        try {
            $reply = $this->rcon->execute($config, 'reloadlua '.BridgeInstaller::FILENAME);
        } catch (RconException $exception) {
            $this->logger->warning('bridge reload could not reach RCON', [
                'server' => $server->getId(),
                'error' => $exception->getMessage(),
            ]);

            return BridgeReloadOutcome::Unknown;
        }

        if (str_contains($reply, self::UNKNOWN_FILE)) {
            return BridgeReloadOutcome::NotReloaded;
        }

        // Neither the success phrase nor the known failure: an unknown
        // answer is not a success, and saying so is the whole point of
        // having more than two outcomes.
        if (!str_contains(strtolower($reply), self::RELOADED)) {
            $this->logger->warning('bridge reload got an answer it does not recognise', [
                'server' => $server->getId(),
                'reply' => mb_substr($reply, 0, 200),
            ]);

            return BridgeReloadOutcome::Unknown;
        }

        return $this->verify($server, $expected, $before);
    }

    /**
     * Reads back from what the running bridge writes, not from the file.
     *
     * The session id has to change: it is minted at module level, so a
     * new one is proof the module was re-executed. Without that check a
     * server.json left over from before the reload would read as
     * success — the same trap as reading back a setter's own field.
     */
    private function verify(GameServer $server, string $expected, ?string $before): BridgeReloadOutcome
    {
        for ($attempt = 0; $attempt < self::READBACK_ATTEMPTS; ++$attempt) {
            if ($this->readbackDelayMicroseconds > 0) {
                usleep($this->readbackDelayMicroseconds);
            }

            $reading = $this->info->runningBridge($server);

            if ($reading === null) {
                continue;
            }

            $session = $reading['sessionId'];

            // Still the pre-reload file: the module has not re-run yet.
            if ($session === null || ($before !== null && $session === $before)) {
                continue;
            }

            $running = $reading['version'];

            if ($running === null) {
                continue;
            }

            if ($running !== $expected) {
                $this->logger->warning('bridge reloaded but the running version does not match', [
                    'server' => $server->getId(),
                    'expected' => $expected,
                    'running' => $running,
                ]);

                return BridgeReloadOutcome::WrongVersion;
            }

            return BridgeReloadOutcome::Active;
        }

        $this->logger->warning('bridge reloaded but never wrote a fresh reading', [
            'server' => $server->getId(),
            'expected' => $expected,
        ]);

        return BridgeReloadOutcome::NotAnswering;
    }

    private function sessionId(GameServer $server): ?string
    {
        return $this->info->runningBridge($server)['sessionId'] ?? null;
    }
}
