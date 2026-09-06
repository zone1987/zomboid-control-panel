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

    /**
     * The abilities the server exposes as plain RCON.
     *
     * Every one takes `-true`/`-false`, so the panel sets a state rather
     * than toggling blind — a toggle against a state nobody read is how
     * two admins end up fighting over one flag. The command names come
     * from the server's own `help` output, which spells three of them
     * differently from its own documentation: `godmodplayer`, not
     * `godmodeplayer`.
     */
    public const ABILITIES = [
        'god' => 'godmodplayer',
        'invisible' => 'invisibleplayer',
        'noclip' => 'noclip',
        'voiceBan' => 'voiceban',
    ];

    public function setAbility(GameServer $server, string $username, string $ability, bool $on): string
    {
        $command = self::ABILITIES[$ability] ?? null;

        if ($command === null) {
            throw new RconCommandFailed(sprintf('Unknown ability "%s".', $ability));
        }

        return $this->run($server, sprintf(
            '%s "%s" -%s',
            $command,
            $this->sanitise($username),
            $on ? 'true' : 'false',
        ));
    }

    /**
     * Grants experience in one skill.
     *
     * The trailing `-true` asks the server to apply the player's own XP
     * multiplier, which is what "give them a level's worth" means on a
     * server that runs one; without it the number is raw.
     */
    public function grantExperience(
        GameServer $server,
        string $username,
        string $perk,
        int $amount,
        bool $withMultiplier = false,
    ): string {
        if ($amount < 1 || $amount > self::MAX_XP) {
            throw new RconCommandFailed(sprintf('XP must be between 1 and %d.', self::MAX_XP));
        }

        // A perk is a bare identifier in the command, unquoted, so
        // anything but letters could inject a further argument.
        if (preg_match('/^[A-Za-z]+$/', $perk) !== 1) {
            throw new RconCommandFailed(sprintf('Unknown skill "%s".', $perk));
        }

        return $this->run($server, sprintf(
            'addxp "%s" %s=%d%s',
            $this->sanitise($username),
            $perk,
            $amount,
            $withMultiplier ? ' -true' : '',
        ));
    }

    /** As much as one command may grant, so a typo cannot max a skill. */
    public const MAX_XP = 100000;

    /**
     * Bans or unbans a SteamID rather than a name.
     *
     * The point of the whole thing: a name can be changed, an id cannot.
     */
    public function banSteamId(GameServer $server, string $steamId): string
    {
        return $this->run($server, sprintf('banid %s', self::steamId($steamId)));
    }

    public function unbanSteamId(GameServer $server, string $steamId): string
    {
        return $this->run($server, sprintf('unbanid %s', self::steamId($steamId)));
    }

    /** Digits only: the id is unquoted in the command. */
    private static function steamId(string $value): string
    {
        $id = trim($value);

        if (preg_match('/^\d{6,20}$/', $id) !== 1) {
            throw new RconCommandFailed('That is not a SteamID.');
        }

        return $id;
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
