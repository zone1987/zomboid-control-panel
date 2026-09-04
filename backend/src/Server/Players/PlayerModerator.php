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

    /** Knox Country spans roughly this square; a cell is 300 tiles. */
    public const WORLD_MIN = 0;
    public const WORLD_MAX = 20000;

    /** Ground is 0, the rest are floors above and basements below. */
    public const LEVEL_MIN = -1;
    public const LEVEL_MAX = 7;

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
     * Moves one player to another.
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

    /**
     * Moves a named player to a point in the world.
     *
     * The two-argument form of "teleportto" is the one that takes a name;
     * the single-argument form moves the caller, which over RCON is
     * nobody and answers a bare "Error".
     *
     * @throws RconException
     */
    public function teleportToCoordinates(GameServer $server, string $username, int $x, int $y, int $z): string
    {
        if (!self::isInsideWorld($x, $y, $z)) {
            throw new RconCommandFailed(sprintf('Coordinates %d,%d,%d lie outside the world.', $x, $y, $z));
        }

        return $this->run($server, sprintf(
            'teleportto "%s" %d,%d,%d',
            $this->sanitise($username),
            $x,
            $y,
            $z,
        ));
    }

    public static function isInsideWorld(int $x, int $y, int $z): bool
    {
        return $x >= self::WORLD_MIN && $x <= self::WORLD_MAX
            && $y >= self::WORLD_MIN && $y <= self::WORLD_MAX
            && $z >= self::LEVEL_MIN && $z <= self::LEVEL_MAX;
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
