<?php

declare(strict_types=1);

namespace App\Server\Players;

use App\Entity\GameServer;
use App\Server\Rcon\RconClientInterface;
use App\Server\Rcon\RconCommandFailed;

/**
 * Player moderation over RCON.
 *
 * Replies are free text, so this reports what the server said rather than
 * pretending to interpret it — an unknown username and a successful kick
 * both come back as prose.
 */
final readonly class PlayerModerator
{
    public const ACCESS_LEVELS = ['admin', 'moderator', 'overseer', 'gm', 'observer', 'none'];

    public function __construct(private RconClientInterface $rcon)
    {
    }

    public function kick(GameServer $server, string $username, ?string $reason = null): string
    {
        $command = $reason === null || trim($reason) === ''
            ? sprintf('kick "%s"', $this->sanitise($username))
            : sprintf('kick "%s" -r "%s"', $this->sanitise($username), $this->sanitise($reason));

        return $this->run($server, $command);
    }

    public function ban(GameServer $server, string $username, ?string $reason = null, bool $alsoIp = false): string
    {
        $parts = [sprintf('banuser "%s"', $this->sanitise($username))];

        if ($alsoIp) {
            $parts[] = '-ip';
        }

        if ($reason !== null && trim($reason) !== '') {
            $parts[] = sprintf('-r "%s"', $this->sanitise($reason));
        }

        return $this->run($server, implode(' ', $parts));
    }

    public function unban(GameServer $server, string $username): string
    {
        return $this->run($server, sprintf('unbanuser "%s"', $this->sanitise($username)));
    }

    /**
     * Moves one player to another. RCON has no command that puts a named
     * player at coordinates -- "teleportto" moves the caller, and RCON
     * has no caller, so it answers "Error".
     *
     * @throws RconException
     */
    public function teleportToPlayer(GameServer $server, string $username, string $target): string
    {
        return $this->run($server, sprintf(
            'teleportplayer "%s" "%s"',
            $this->sanitise($username),
            $this->sanitise($target),
        ));
    }

    public function setAccessLevel(GameServer $server, string $username, string $level): string
    {
        if (!\in_array($level, self::ACCESS_LEVELS, true)) {
            throw new RconCommandFailed(sprintf('Unknown access level "%s".', $level));
        }

        return $this->run($server, sprintf('setaccesslevel "%s" %s', $this->sanitise($username), $level));
    }

    public function message(GameServer $server, string $text): string
    {
        return $this->run($server, sprintf('servermsg "%s"', $this->sanitise($text)));
    }

    private function run(GameServer $server, string $command): string
    {
        $config = $server->getRconConfig();

        if ($config === null) {
            throw new RconCommandFailed('This server has no RCON credentials.');
        }

        return $this->rcon->execute($config, $command);
    }

    /**
     * Strips quotes and control characters. Arguments are quoted, so a
     * stray quote would end the argument early and turn the rest of the
     * value into further arguments.
     */
    private function sanitise(string $value): string
    {
        return trim(preg_replace('/["\r\n\x00-\x1f]+/', '', $value) ?? '');
    }
}
