<?php

declare(strict_types=1);

namespace App\Server\Config;

use App\Entity\GameServer;
use App\Server\Rcon\RconClientInterface;
use App\Server\Rcon\RconException;
use Psr\Log\LoggerInterface;

/**
 * Makes a saved INI change take effect without a restart, and proves it.
 *
 * Only the INI: the sandbox cannot be reloaded from outside the game at
 * all — see ConfigApplyOutcome for the measurement and the reason — so
 * asking for it here answers `RestartNeeded` without sending anything.
 *
 * The proof is `showoptions`, which prints what the *running* server
 * holds. Sending `reloadoptions` and believing its "Options reloaded"
 * would be the same mistake as trusting `Lua file reloaded`, which was
 * measured to mean nothing about the value.
 */
final readonly class ConfigReloader
{
    private const RELOADED = 'options reloaded';

    public function __construct(
        private RconClientInterface $rcon,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, bool|float|int|string> $written what changed, for the read-back
     */
    public function apply(GameServer $server, ConfigKind $kind, array $written): ConfigApplyOutcome
    {
        if ($kind === ConfigKind::Sandbox) {
            return ConfigApplyOutcome::RestartNeeded;
        }

        $config = $server->getRconConfig();

        if ($config === null) {
            return ConfigApplyOutcome::NoRcon;
        }

        try {
            $reply = $this->rcon->execute($config, 'reloadoptions');

            if (!str_contains(strtolower($reply), self::RELOADED)) {
                $this->logger->warning('reloadoptions answered something unrecognised', [
                    'server' => $server->getId(),
                    'reply' => mb_substr($reply, 0, 200),
                ]);

                return ConfigApplyOutcome::ReloadUnknown;
            }

            if ($written === []) {
                return ConfigApplyOutcome::Applied;
            }

            $running = $this->rcon->execute($config, 'showoptions');
        } catch (RconException $exception) {
            $this->logger->warning('could not reload the server options', [
                'server' => $server->getId(),
                'error' => $exception->getMessage(),
            ]);

            return ConfigApplyOutcome::ReloadUnknown;
        }

        return $this->confirms($running, $written)
            ? ConfigApplyOutcome::Applied
            : ConfigApplyOutcome::ReloadUnconfirmed;
    }

    /**
     * Whether the running server reports every value that was written.
     *
     * `showoptions` prints `* Key=value` per line. A value it does not
     * print at all counts as unconfirmed rather than as agreement.
     *
     * @param array<string, bool|float|int|string> $written
     */
    private function confirms(string $running, array $written): bool
    {
        foreach ($written as $key => $value) {
            $pattern = sprintf('/^\*?\s*%s\s*=\s*(.*)$/mi', preg_quote($key, '/'));

            if (preg_match($pattern, $running, $match) !== 1) {
                return false;
            }

            if (!$this->same(trim($match[1]), $value)) {
                return false;
            }
        }

        return true;
    }

    private function same(string $reported, bool|float|int|string $wanted): bool
    {
        if (is_bool($wanted)) {
            return $reported === ($wanted ? 'true' : 'false');
        }

        if (is_numeric($reported) && is_numeric($wanted)) {
            return abs((float) $reported - (float) $wanted) < 0.000001;
        }

        return $reported === (string) $wanted;
    }
}
